<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Radiology\Application\Exceptions\AdmissionNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\AppointmentNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\PatientNotEligibleForRadiologyOrderException;
use App\Modules\Radiology\Application\Exceptions\RadiologyOrderProcedureCatalogItemNotEligibleException;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderAuditLogRepositoryInterface;
use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;
use App\Modules\Radiology\Domain\Services\AdmissionLookupServiceInterface;
use App\Modules\Radiology\Domain\Services\AppointmentLookupServiceInterface;
use App\Modules\Radiology\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Radiology\Domain\Services\RadiologyProcedureCatalogLookupServiceInterface;
use App\Modules\Radiology\Domain\ValueObjects\RadiologyOrderStatus;
use App\Modules\ServiceRequest\Application\UseCases\LinkServiceRequestToClinicalOrderUseCase;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use App\Support\ClinicalOrders\OrderSessionManager;
use Illuminate\Support\Str;
use RuntimeException;

class CreateRadiologyOrderUseCase
{
    public function __construct(
        private readonly RadiologyOrderAuditLogRepositoryInterface $auditLogRepository,
        private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository,
        private readonly PatientLookupServiceInterface $patientLookupService,
        private readonly AppointmentLookupServiceInterface $appointmentLookupService,
        private readonly AdmissionLookupServiceInterface $admissionLookupService,
        private readonly RadiologyProcedureCatalogLookupServiceInterface $radiologyProcedureCatalogLookupService,
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
            throw new PatientNotEligibleForRadiologyOrderException(
                'Radiology order can only be created for an existing patient.',
            );
        }

        $serviceRequestId = trim((string) ($payload['service_request_id'] ?? ''));
        unset($payload['service_request_id']);
        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->assertLinkable($serviceRequestId, $patientId, 'radiology');
        }

        $appointmentId = $payload['appointment_id'] ?? null;
        if ($appointmentId !== null && ! $this->appointmentLookupService->isValidForPatient((string) $appointmentId, $patientId)) {
            throw new AppointmentNotEligibleForRadiologyOrderException(
                'Appointment is not valid for the selected patient.',
            );
        }

        $admissionId = $payload['admission_id'] ?? null;
        if ($admissionId !== null && ! $this->admissionLookupService->isValidForPatient((string) $admissionId, $patientId)) {
            throw new AdmissionNotEligibleForRadiologyOrderException(
                'Admission is not valid for the selected patient.',
            );
        }

        $this->applyCatalogManagedProcedureSelection($payload);
        $entryState = ClinicalOrderLifecycle::normalizeEntryState(
            isset($payload['entry_mode']) ? (string) $payload['entry_mode'] : null,
        );

        if (
            ! array_key_exists('ordered_at', $payload)
            || blank($payload['ordered_at'])
        ) {
            $payload['ordered_at'] = now();
        }

        $payload['status'] = RadiologyOrderStatus::ORDERED->value;
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

        $createdOrder = $this->radiologyOrderRepository->create($payload);

        if (! blank($payload['clinical_order_session_id'] ?? null)) {
            $this->orderSessionManager->incrementItemCount((string) $payload['clinical_order_session_id']);
        }

        $this->auditLogRepository->write(
            radiologyOrderId: $createdOrder['id'],
            action: 'radiology-order.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($createdOrder),
            ],
        );

        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->complete(
                serviceRequestId: $serviceRequestId,
                patientId: $patientId,
                serviceType: ServiceRequestServiceType::RADIOLOGY->value,
                linkedOrderType: ServiceRequestServiceType::RADIOLOGY->linkedOrderType(),
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
            $candidate = 'RAD'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->radiologyOrderRepository->existsByOrderNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique radiology order number.');
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
            'radiology_procedure_catalog_item_id',
            'procedure_code',
            'modality',
            'study_description',
            'clinical_indication',
            'scheduled_for',
            'report_summary',
            'completed_at',
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

    private function applyCatalogManagedProcedureSelection(array &$payload): void
    {
        $catalogItemId = isset($payload['radiology_procedure_catalog_item_id'])
            ? trim((string) $payload['radiology_procedure_catalog_item_id'])
            : '';
        $procedureCode = isset($payload['procedure_code'])
            ? trim((string) $payload['procedure_code'])
            : '';

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->radiologyProcedureCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($procedureCode !== '') {
            $catalogItem = $this->radiologyProcedureCatalogLookupService->findActiveByCode($procedureCode);
        }

        if ($catalogItem === null) {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedProcedureCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedStudyDescription = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure catalog entry is missing required identifier.'
            );
        }

        if ($resolvedProcedureCode === '' || $resolvedStudyDescription === '') {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure catalog entry is missing required code or name.'
            );
        }

        if (strlen($resolvedProcedureCode) > 100) {
            throw new RadiologyOrderProcedureCatalogItemNotEligibleException(
                'Selected radiology procedure code exceeds the supported radiology order length.'
            );
        }

        $payload['radiology_procedure_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['procedure_code'] = $resolvedProcedureCode;
        $payload['study_description'] = $resolvedStudyDescription;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveClinicalOrderSessionId(array $payload, ?int $actorId): string
    {
        $session = $this->orderSessionManager->ensureSession(
            module: 'radiology',
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
     * @param array<string, mixed> $payload
     */
    private function applyLifecycleLinkage(array &$payload): void
    {
        $replacesOrderId = trim((string) ($payload['replaces_order_id'] ?? ''));
        $addOnToOrderId = trim((string) ($payload['add_on_to_order_id'] ?? ''));

        ClinicalOrderLifecycle::assertNoConflictingLinkage($replacesOrderId, $addOnToOrderId);

        if ($replacesOrderId !== '') {
            $sourceOrder = $this->radiologyOrderRepository->findById($replacesOrderId);
            ClinicalOrderLifecycle::assertReplacementSource(
                $sourceOrder,
                $payload,
                'replacesOrderId',
                'radiology order',
            );
            $payload['replaces_order_id'] = $replacesOrderId;
        } else {
            $payload['replaces_order_id'] = null;
        }

        if ($addOnToOrderId !== '') {
            $sourceOrder = $this->radiologyOrderRepository->findById($addOnToOrderId);
            ClinicalOrderLifecycle::assertAddOnSource(
                $sourceOrder,
                $payload,
                'addOnToOrderId',
                'radiology order',
            );
            $payload['add_on_to_order_id'] = $addOnToOrderId;
        } else {
            $payload['add_on_to_order_id'] = null;
        }
    }
}
