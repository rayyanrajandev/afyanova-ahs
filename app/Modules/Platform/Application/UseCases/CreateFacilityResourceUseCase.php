<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicateFacilityResourceCodeException;
use App\Modules\Platform\Domain\Repositories\FacilityResourceAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceStatus;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;

class CreateFacilityResourceUseCase
{
    public function __construct(
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly FacilityResourceAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $resourceType, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();
        $code = $this->normalizeCode((string) $payload['code']);

        if ($this->facilityResourceRepository->existsByCodeInScope($resourceType, $code, $tenantId, $facilityId)) {
            throw new DuplicateFacilityResourceCodeException('Resource code already exists for the current scope.');
        }

        $createPayload = [
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'resource_type' => $resourceType,
            'code' => $code,
            'name' => trim((string) $payload['name']),
            'department_id' => $this->nullableTrimmedValue($payload['department_id'] ?? null),
            'service_point_type' => null,
            'ward_name' => null,
            'bed_number' => null,
            'gender_restriction' => null,
            'location' => $this->nullableTrimmedValue($payload['location'] ?? null),
            'status' => FacilityResourceStatus::ACTIVE->value,
            'status_reason' => null,
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
        ];

        if ($resourceType === FacilityResourceType::SERVICE_POINT->value) {
            $createPayload['service_point_type'] = $this->nullableTrimmedValue($payload['service_point_type'] ?? null);
        }

        // Ward-beds and observation rooms are both patient-placed, billable
        // resources (occupancy-tracked via bed_resource_id + priced via
        // chargeable_item_id) — they share the same storage fields.
        // gender_restriction is generic here too, not observation-room-only,
        // so ward-beds can adopt gender segregation later with no new code.
        if (in_array($resourceType, [FacilityResourceType::WARD_BED->value, FacilityResourceType::OBSERVATION_ROOM->value], true)) {
            $createPayload['ward_name'] = $this->nullableTrimmedValue($payload['ward_name'] ?? null);
            $createPayload['bed_number'] = $this->nullableTrimmedValue($payload['bed_number'] ?? null);
            $createPayload['chargeable_item_id'] = $this->nullableTrimmedValue($payload['chargeable_item_id'] ?? null);
            $createPayload['gender_restriction'] = $this->nullableTrimmedValue($payload['gender_restriction'] ?? null);
        }

        $created = $this->facilityResourceRepository->create($createPayload);

        $this->auditLogRepository->write(
            facilityResourceId: $created['id'],
            action: 'facility-resource.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
            metadata: [
                'resourceType' => $resourceType,
            ],
        );

        return $created;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $resource): array
    {
        $tracked = [
            'tenant_id',
            'facility_id',
            'resource_type',
            'code',
            'name',
            'department_id',
            'service_point_type',
            'ward_name',
            'bed_number',
            'gender_restriction',
            'location',
            'status',
            'status_reason',
            'notes',
            'chargeable_item_id',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $resource[$field] ?? null;
        }

        return $result;
    }
}

