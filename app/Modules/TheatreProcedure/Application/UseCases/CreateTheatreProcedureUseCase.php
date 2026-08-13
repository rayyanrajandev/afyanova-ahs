<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\ServiceRequest\Application\UseCases\LinkServiceRequestToClinicalOrderUseCase;
use App\Modules\ServiceRequest\Domain\ValueObjects\ServiceRequestServiceType;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureCatalogItemNotEligibleException;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreRoomServicePointNotEligibleException;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Services\TheatreProcedureCatalogLookupServiceInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;
use App\Support\ClinicalOrders\OrderSessionManager;
use Illuminate\Support\Str;
use RuntimeException;

class CreateTheatreProcedureUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TheatreProcedureCatalogLookupServiceInterface $theatreProcedureCatalogLookupService,
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly OrderSessionManager $orderSessionManager,
        private readonly LinkServiceRequestToClinicalOrderUseCase $serviceRequestLinker,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $patientId = (string) $payload['patient_id'];
        $serviceRequestId = trim((string) ($payload['service_request_id'] ?? ''));
        unset($payload['service_request_id']);
        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->assertLinkable($serviceRequestId, $patientId, 'theatre_procedure');
        }

        $scheduledAt = $payload['scheduled_at'] ?? null;
        $status = $payload['status'] ?? TheatreProcedureStatus::PLANNED->value;
        $entryState = ClinicalOrderLifecycle::normalizeEntryState(
            isset($payload['entry_mode']) ? (string) $payload['entry_mode'] : null,
        );
        if (! in_array($status, TheatreProcedureStatus::values(), true)) {
            $status = TheatreProcedureStatus::PLANNED->value;
        }

        $this->applyCatalogManagedProcedureSelection($payload);
        $this->applyTheatreRoomSelection($payload);
        $this->applyLifecycleLinkage($payload);

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $clinicalOrderSessionId = $this->resolveClinicalOrderSessionId(
            array_merge($payload, [
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
            ]),
            $actorId,
        );

        $createPayload = [
            'procedure_number' => $this->generateProcedureNumber(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $payload['patient_id'],
            'admission_id' => $payload['admission_id'] ?? null,
            'appointment_id' => $payload['appointment_id'] ?? null,
            'clinical_order_session_id' => $clinicalOrderSessionId,
            'replaces_order_id' => $payload['replaces_order_id'] ?? null,
            'add_on_to_order_id' => $payload['add_on_to_order_id'] ?? null,
            'theatre_procedure_catalog_item_id' => $payload['theatre_procedure_catalog_item_id'],
            'procedure_type' => $payload['procedure_type'],
            'procedure_name' => $payload['procedure_name'] ?? null,
            'operating_clinician_user_id' => $payload['operating_clinician_user_id'],
            'anesthetist_user_id' => $payload['anesthetist_user_id'] ?? null,
            'theatre_room_service_point_id' => $payload['theatre_room_service_point_id'] ?? null,
            'theatre_room_name' => $payload['theatre_room_name'] ?? null,
            'scheduled_at' => $scheduledAt,
            'started_at' => $status === TheatreProcedureStatus::IN_PROGRESS->value
                ? ($payload['started_at'] ?? now())
                : null,
            'completed_at' => $status === TheatreProcedureStatus::COMPLETED->value
                ? ($payload['completed_at'] ?? now())
                : null,
            'status' => $status,
            'entry_state' => null,
            'signed_at' => null,
            'signed_by_user_id' => null,
            'status_reason' => $payload['status_reason'] ?? null,
            'lifecycle_locked_at' => null,
            'notes' => $payload['notes'] ?? null,
        ];

        if ($entryState === 'draft') {
            ClinicalOrderLifecycle::applyDraftEntryState($createPayload);
        } else {
            ClinicalOrderLifecycle::applyActiveEntryState($createPayload, $actorId);
        }

        $created = $this->theatreProcedureRepository->create($createPayload);

        $this->orderSessionManager->incrementItemCount($clinicalOrderSessionId);

        $this->auditLogRepository->write(
            theatreProcedureId: $created['id'],
            action: 'theatre-procedure.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        if ($serviceRequestId !== '') {
            $this->serviceRequestLinker->complete(
                serviceRequestId: $serviceRequestId,
                patientId: $patientId,
                serviceType: ServiceRequestServiceType::THEATRE_PROCEDURE->value,
                linkedOrderType: ServiceRequestServiceType::THEATRE_PROCEDURE->linkedOrderType(),
                linkedOrderId: (string) $created['id'],
                linkedOrderNumber: isset($created['procedure_number']) ? (string) $created['procedure_number'] : null,
                actorId: $actorId,
            );
        }

        return $created;
    }

    private function generateProcedureNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'THR'.now()->format('Ymd').strtoupper(Str::random(5));
            if (! $this->theatreProcedureRepository->existsByProcedureNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique theatre procedure number.');
    }

    private function applyCatalogManagedProcedureSelection(array &$payload): void
    {
        $catalogItemId = isset($payload['theatre_procedure_catalog_item_id'])
            ? trim((string) $payload['theatre_procedure_catalog_item_id'])
            : '';
        $procedureType = isset($payload['procedure_type'])
            ? trim((string) $payload['procedure_type'])
            : '';

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->theatreProcedureCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($procedureType !== '') {
            $catalogItem = $this->theatreProcedureCatalogLookupService->findActiveByCode($procedureType);
        }

        if ($catalogItem === null) {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure is not available in the active clinical catalog.',
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedProcedureCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedProcedureName = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure catalog entry is missing required identifier.',
            );
        }

        if ($resolvedProcedureCode === '' || $resolvedProcedureName === '') {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure catalog entry is missing required code or name.',
            );
        }

        if (strlen($resolvedProcedureCode) > 120) {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure code exceeds the supported procedure type length.',
            );
        }

        if (strlen($resolvedProcedureName) > 180) {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure name exceeds the supported procedure name length.',
            );
        }

        $payload['theatre_procedure_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['procedure_type'] = $resolvedProcedureCode;
        $payload['procedure_name'] = $resolvedProcedureName;
    }

    private function applyTheatreRoomSelection(array &$payload): void
    {
        $servicePointId = trim((string) ($payload['theatre_room_service_point_id'] ?? ''));
        $roomName = trim((string) ($payload['theatre_room_name'] ?? ''));

        if ($servicePointId === '') {
            $payload['theatre_room_service_point_id'] = null;
            $payload['theatre_room_name'] = $roomName !== '' ? $roomName : null;
            return;
        }

        $servicePoint = $this->facilityResourceRepository->findById($servicePointId);
        if ($servicePoint === null) {
            throw new TheatreRoomServicePointNotEligibleException('Selected theatre room could not be found in the active service-point registry.');
        }

        if (($servicePoint['resource_type'] ?? null) !== 'service_point') {
            throw new TheatreRoomServicePointNotEligibleException('Selected theatre room is not a service-point registry record.');
        }

        if (strtolower(trim((string) ($servicePoint['status'] ?? ''))) !== 'active') {
            throw new TheatreRoomServicePointNotEligibleException('Selected theatre room is inactive and cannot be scheduled.');
        }

        if (! $this->isEligibleTheatreRoomServicePoint($servicePoint)) {
            throw new TheatreRoomServicePointNotEligibleException('Selected service point is not configured as an operating theatre or procedure room.');
        }

        $resolvedRoomName = trim((string) ($servicePoint['name'] ?? $servicePoint['code'] ?? ''));
        if ($resolvedRoomName === '') {
            throw new TheatreRoomServicePointNotEligibleException('Selected theatre room is missing a usable room name.');
        }

        $payload['theatre_room_service_point_id'] = $servicePointId;
        $payload['theatre_room_name'] = $resolvedRoomName;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveClinicalOrderSessionId(array $payload, ?int $actorId): string
    {
        $session = $this->orderSessionManager->ensureSession(
            module: 'theatre',
            requestedSessionId: isset($payload['clinical_order_session_id'])
                ? (string) $payload['clinical_order_session_id']
                : null,
            context: [
                'tenant_id' => $payload['tenant_id'] ?? null,
                'facility_id' => $payload['facility_id'] ?? null,
                'patient_id' => $payload['patient_id'] ?? null,
                'appointment_id' => $payload['appointment_id'] ?? null,
                'admission_id' => $payload['admission_id'] ?? null,
                'ordered_by_user_id' => $actorId,
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
            $sourceProcedure = $this->theatreProcedureRepository->findById($replacesOrderId);
            ClinicalOrderLifecycle::assertReplacementSource(
                $sourceProcedure,
                $payload,
                'replacesOrderId',
                'theatre procedure',
            );
            $payload['replaces_order_id'] = $replacesOrderId;
        } else {
            $payload['replaces_order_id'] = null;
        }

        if ($addOnToOrderId !== '') {
            $sourceProcedure = $this->theatreProcedureRepository->findById($addOnToOrderId);
            ClinicalOrderLifecycle::assertAddOnSource(
                $sourceProcedure,
                $payload,
                'addOnToOrderId',
                'theatre procedure',
            );
            $payload['add_on_to_order_id'] = $addOnToOrderId;
        } else {
            $payload['add_on_to_order_id'] = null;
        }
    }

    private function isEligibleTheatreRoomServicePoint(array $servicePoint): bool
    {
        $servicePointType = strtolower(trim((string) ($servicePoint['service_point_type'] ?? '')));
        if (in_array($servicePointType, [
            'operating_theatre',
            'emergency_theatre',
            'obstetric_theatre',
            'procedure_room',
            'dressing_room',
        ], true)) {
            return true;
        }

        $haystack = strtolower(trim(implode(' ', array_filter([
            (string) ($servicePoint['code'] ?? ''),
            (string) ($servicePoint['name'] ?? ''),
            (string) ($servicePoint['service_point_type'] ?? ''),
            (string) ($servicePoint['location'] ?? ''),
        ]))));

        foreach (['theatre', 'operating', 'surgery', 'surgical', 'procedure', 'dressing'] as $token) {
            if (str_contains($haystack, $token)) {
                return true;
            }
        }

        return false;
    }
}
