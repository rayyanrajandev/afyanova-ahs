<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Application\Exceptions\AdmissionNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Application\Exceptions\AppointmentNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Application\Exceptions\LaboratoryOrderTestCatalogItemNotEligibleException;
use App\Modules\Laboratory\Application\Exceptions\PatientNotEligibleForLaboratoryOrderException;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderAuditLogRepositoryInterface;
use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Laboratory\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Laboratory\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Laboratory\Domain\Services\LabTestCatalogLookupServiceInterface;
use App\Modules\Laboratory\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Application\UseCases\LinkServiceRequestToClinicalOrderUseCase;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use App\Support\ClinicalOrders\OrderSessionManager;
use Illuminate\Support\Str;
use RuntimeException;

class CreateLaboratoryOrderUseCase
{
    public function __construct(
        private readonly LaboratoryOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly LabTestCatalogLookupServiceInterface $labTestCatalogLookupService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly OrderSessionManager $orderSessionManager,
        private readonly LinkServiceRequestToClinicalOrderUseCase $serviceRequestLinker,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        if (! $this->patientLookupService->patientExists($patientId)) {
            throw new PatientNotEligibleForLaboratoryOrderException(
                'Laboratory order can only be created for an existing patient.',
            );
        }

        $serviceRequestId = trim((string) ($payload['service_request_id'] ?? ''));
        unset($payload['service_request_id']);
        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->assertLinkable($serviceRequestId, $patientId, 'laboratory');
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        if ($appointmentId !== null && ! $this->appointmentLookupService->isValidForPatient((string) $appointmentId, $patientId)) {
            throw new AppointmentNotEligibleForLaboratoryOrderException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? null;
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForLaboratoryOrderException(
                'Admission is not valid for the selected patient.',
            );
        }

        $this->applyCatalogManagedLabTestSelection($payload);
        $entryState = ClinicalOrderLifecycle::normalizeEntryState(
            isset($payload['entry_mode']) ? (string) $payload['entry_mode'] : null,
        );

        if (
            ! array_key_exists('ordered_at', $payload)
            || blank($payload['ordered_at'])
        ) {
            $payload['ordered_at'] = now();
        }

        $payload['status'] = LaboratoryOrderStatus::ORDERED->value;
        $payload['order_number'] = $this->generateOrderNumber();
        $payload['tenant_id'] = $this->platformScopeContext->tenantId();
        $payload['facility_id'] = $this->platformScopeContext->facilityId();
        $payload['ordered_by_user_id'] = $payload['ordered_by_user_id'] ?? $actorId;

        $this->applyLifecycleLinkage($payload);
        $payload['clinical_order_session_id'] = $this->resolveClinicalOrderSessionId(
            $payload,
            $actorId,
        );
        if ($entryState === 'draft') {
            ClinicalOrderLifecycle::applyDraftEntryState($payload);
        } else {
            ClinicalOrderLifecycle::applyActiveEntryState($payload, $actorId);
        }

        $createdOrder = $this->laboratoryOrderRepository->create($payload);

        if (! blank($payload['clinical_order_session_id'] ?? null)) {
            $this->orderSessionManager->incrementItemCount((string) $payload['clinical_order_session_id']);
        }

        $this->auditLogRepository->write(
            laboratoryOrderId: $createdOrder['id'],
            action: 'laboratory-order.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdOrder),
            ],
        );

        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->complete(
                serviceRequestId: $serviceRequestId,
                patientId: $patientId,
                serviceType: ServiceRequestServiceType::LABORATORY->value,
                linkedOrderType: ServiceRequestServiceType::LABORATORY->linkedOrderType(),
                linkedOrderId: (string) $createdOrder['id'],
                linkedOrderNumber: isset($createdOrder['order_number']) ? (string) $createdOrder['order_number'] : null,
                actorId: $actorId,
            );
        }

        return $createdOrder;
    }

    private function generateOrderNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'LAB'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->laboratoryOrderRepository->existsByOrderNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique laboratory order number.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $order): array
    {
        $tracked = [
            'order_number',
            'tenant_id',
            'facility_id',
            'patient_id',
            'admission_id',
            'appointment_id',
            'clinical_order_session_id',
            'replaces_order_id',
            'add_on_to_order_id',
            'ordered_by_user_id',
            'ordered_at',
            'lab_test_catalog_item_id',
            'test_code',
            'test_name',
            'priority',
            'specimen_type',
            'clinical_notes',
            'result_summary',
            'resulted_at',
            'status',
            'entry_state',
            'signed_at',
            'signed_by_user_id',
            'status_reason',
            'lifecycle_reason_code',
            'entered_in_error_at',
            'entered_in_error_by_user_id',
            'lifecycle_locked_at',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $order[$field] ?? null;
        }

        return $result;
    }

    private function applyCatalogManagedLabTestSelection(array &$payload): void
    {
        $catalogItemId = isset($payload['lab_test_catalog_item_id'])
            ? trim((string) ($payload['lab_test_catalog_item_id'] ?? ''))
            : '';
        $testCode = isset($payload['test_code'])
            ? trim((string) ($payload['test_code'] ?? ''))
            : '';

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->labTestCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($testCode !== '') {
            $catalogItem = $this->labTestCatalogLookupService->findActiveByCode($testCode);
        }

        if ($catalogItem === null) {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test is not available in the active clinical catalog.'
            );
        }

        $catalogCode = trim((string) ($catalogItem['code'] ?? ''));
        $catalogName = trim((string) ($catalogItem['name'] ?? ''));

        if ($catalogCode === '' || $catalogName === '') {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test catalog entry is missing required code or name.'
            );
        }

        if (strlen($catalogCode) > 50) {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test code exceeds the supported laboratory order length.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        if ($resolvedCatalogItemId === '') {
            throw new LaboratoryOrderTestCatalogItemNotEligibleException(
                'Selected laboratory test catalog entry is missing required identifier.'
            );
        }

        $payload['lab_test_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['test_code'] = $catalogCode;
        $payload['test_name'] = $catalogName;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveClinicalOrderSessionId(array $payload, ?int $actorId): string
    {
        $session = $this->orderSessionManager->ensureSession(
            module: 'laboratory',
            requestedSessionId: isset($payload['clinical_order_session_id'])
                ? (string) $payload['clinical_order_session_id']
                : null,
            context: [
                'tenant_id' => $payload['tenant_id'] ?? null,
                'facility_id' => $payload['facility_id'] ?? null,
                'patient_id' => $payload['patient_id'] ?? null,
                'appointment_id' => $payload['appointment_id'] ?? null,
                'admission_id' => $payload['admission_id'] ?? null,
                'ordered_by_user_id' => $payload['ordered_by_user_id'] ?? $actorId,
                'submitted_at' => now(),
            ],
        );

        return (string) ($session['id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyLifecycleLinkage(array &$payload): void
    {
        $replacesOrderId = trim((string) ($payload['replaces_order_id'] ?? ''));
        $addOnToOrderId = trim((string) ($payload['add_on_to_order_id'] ?? ''));

        ClinicalOrderLifecycle::assertNoConflictingLinkage($replacesOrderId, $addOnToOrderId);

        if ($replacesOrderId !== '') {
            $sourceOrder = $this->laboratoryOrderRepository->findById($replacesOrderId);
            ClinicalOrderLifecycle::assertReplacementSource(
                $sourceOrder,
                $payload,
                'replacesOrderId',
                'laboratory order',
            );
            $payload['replaces_order_id'] = $replacesOrderId;
        } else {
            $payload['replaces_order_id'] = null;
        }

        if ($addOnToOrderId !== '') {
            $sourceOrder = $this->laboratoryOrderRepository->findById($addOnToOrderId);
            ClinicalOrderLifecycle::assertAddOnSource(
                $sourceOrder,
                $payload,
                'addOnToOrderId',
                'laboratory order',
            );
            $payload['add_on_to_order_id'] = $addOnToOrderId;
        } else {
            $payload['add_on_to_order_id'] = null;
        }
    }
}
