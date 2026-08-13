<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureCatalogItemNotEligibleException;
use App\Modules\TheatreProcedure\Application\Exceptions\TheatreRoomServicePointNotEligibleException;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Services\TheatreProcedureCatalogLookupServiceInterface;
use App\Support\ClinicalOrders\ClinicalOrderLifecycle;

class UpdateTheatreProcedureUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureAuditLogRepositoryInterface $auditLogRepository,
        private readonly TheatreProcedureCatalogLookupServiceInterface $theatreProcedureCatalogLookupService,
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->theatreProcedureRepository->findById($id);
        if (! $existing) {
            return null;
        }

        ClinicalOrderLifecycle::assertDraftEditable($existing, 'theatre procedure');

        $working = $existing;
        foreach ($payload as $field => $value) {
            $working[$field] = $value;
        }

        $catalogSelectionChanged = array_key_exists('theatre_procedure_catalog_item_id', $payload)
            || array_key_exists('procedure_type', $payload);
        $roomSelectionChanged = array_key_exists('theatre_room_service_point_id', $payload)
            || array_key_exists('theatre_room_name', $payload);

        if ($catalogSelectionChanged) {
            $this->applyCatalogManagedProcedureSelection($working, $payload);
        }

        if ($roomSelectionChanged) {
            $this->applyTheatreRoomSelection($working, $payload);
        }

        $updatePayload = [];
        foreach ([
            'operating_clinician_user_id',
            'anesthetist_user_id',
            'scheduled_at',
            'notes',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $updatePayload[$field] = $working[$field];
            }
        }

        if ($catalogSelectionChanged) {
            foreach ([
                'theatre_procedure_catalog_item_id',
                'procedure_type',
                'procedure_name',
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        if ($roomSelectionChanged) {
            foreach ([
                'theatre_room_service_point_id',
                'theatre_room_name',
            ] as $field) {
                $updatePayload[$field] = $working[$field] ?? null;
            }
        }

        $updated = $this->theatreProcedureRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            theatreProcedureId: $id,
            action: 'theatre-procedure.updated',
            actorId: $actorId,
            changes: $this->collectChanges($existing, $updated, array_keys($updatePayload)),
        );

        return $updated;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $incomingPayload
     */
    private function applyCatalogManagedProcedureSelection(array &$payload, array $incomingPayload): void
    {
        $catalogItemId = array_key_exists('theatre_procedure_catalog_item_id', $incomingPayload)
            ? trim((string) ($incomingPayload['theatre_procedure_catalog_item_id'] ?? ''))
            : '';
        $procedureType = array_key_exists('procedure_type', $incomingPayload)
            ? trim((string) ($incomingPayload['procedure_type'] ?? ''))
            : '';

        if ($catalogItemId === '' && $procedureType === '') {
            $catalogItemId = trim((string) ($payload['theatre_procedure_catalog_item_id'] ?? ''));
            $procedureType = trim((string) ($payload['procedure_type'] ?? ''));
        }

        $catalogItem = null;
        if ($catalogItemId !== '') {
            $catalogItem = $this->theatreProcedureCatalogLookupService->findActiveById($catalogItemId);
        } elseif ($procedureType !== '') {
            $catalogItem = $this->theatreProcedureCatalogLookupService->findActiveByCode($procedureType);
        }

        if ($catalogItem === null) {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure is not available in the active clinical catalog.'
            );
        }

        $resolvedCatalogItemId = trim((string) ($catalogItem['id'] ?? ''));
        $resolvedProcedureCode = trim((string) ($catalogItem['code'] ?? ''));
        $resolvedProcedureName = trim((string) ($catalogItem['name'] ?? ''));

        if ($resolvedCatalogItemId === '') {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure catalog entry is missing required identifier.'
            );
        }

        if ($resolvedProcedureCode === '' || $resolvedProcedureName === '') {
            throw new TheatreProcedureCatalogItemNotEligibleException(
                'Selected theatre procedure catalog entry is missing required code or name.'
            );
        }

        $payload['theatre_procedure_catalog_item_id'] = $resolvedCatalogItemId;
        $payload['procedure_type'] = $resolvedProcedureCode;
        $payload['procedure_name'] = $resolvedProcedureName;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $incomingPayload
     */
    private function applyTheatreRoomSelection(array &$payload, array $incomingPayload): void
    {
        $servicePointId = array_key_exists('theatre_room_service_point_id', $incomingPayload)
            ? trim((string) ($incomingPayload['theatre_room_service_point_id'] ?? ''))
            : '';
        $roomName = array_key_exists('theatre_room_name', $incomingPayload)
            ? trim((string) ($incomingPayload['theatre_room_name'] ?? ''))
            : '';

        if ($servicePointId === '' && $roomName === '') {
            $servicePointId = trim((string) ($payload['theatre_room_service_point_id'] ?? ''));
            $roomName = trim((string) ($payload['theatre_room_name'] ?? ''));
        }

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
     * @param array<string, mixed> $servicePoint
     */
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

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @param array<int, string> $fields
     * @return array<string, array<string, mixed>>
     */
    private function collectChanges(array $before, array $after, array $fields): array
    {
        $changes = [];

        foreach ($fields as $field) {
            $changes[$field] = [
                'before' => $before[$field] ?? null,
                'after' => $after[$field] ?? null,
            ];
        }

        return $changes;
    }
}
