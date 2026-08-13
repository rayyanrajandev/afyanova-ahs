<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Exceptions\DuplicateFacilityResourceCodeException;
use App\Modules\Platform\Domain\Repositories\FacilityResourceAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateFacilityResourceUseCase
{
    public function __construct(
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly FacilityResourceAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $resourceType, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->facilityResourceRepository->findById($id);
        if (! $existing || ($existing['resource_type'] ?? null) !== $resourceType) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('code', $payload)) {
            $normalizedCode = $this->normalizeCode((string) $payload['code']);

            if ($this->facilityResourceRepository->existsByCodeInScope(
                resourceType: $resourceType,
                code: $normalizedCode,
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeId: $id,
            )) {
                throw new DuplicateFacilityResourceCodeException('Resource code already exists for the current scope.');
            }

            $updatePayload['code'] = $normalizedCode;
        }

        if (array_key_exists('name', $payload)) {
            $updatePayload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('department_id', $payload)) {
            $updatePayload['department_id'] = $this->nullableTrimmedValue($payload['department_id']);
        }

        if (array_key_exists('location', $payload)) {
            $updatePayload['location'] = $this->nullableTrimmedValue($payload['location']);
        }

        if (array_key_exists('notes', $payload)) {
            $updatePayload['notes'] = $this->nullableTrimmedValue($payload['notes']);
        }

        if ($resourceType === 'service_point' && array_key_exists('service_point_type', $payload)) {
            $updatePayload['service_point_type'] = $this->nullableTrimmedValue($payload['service_point_type']);
        }

        // Ward-beds and observation rooms share the same storage fields —
        // see CreateFacilityResourceUseCase for why.
        if (in_array($resourceType, ['ward_bed', 'observation_room'], true)) {
            if (array_key_exists('ward_name', $payload)) {
                $updatePayload['ward_name'] = $this->nullableTrimmedValue($payload['ward_name']);
            }
            if (array_key_exists('bed_number', $payload)) {
                $updatePayload['bed_number'] = $this->nullableTrimmedValue($payload['bed_number']);
            }
            if (array_key_exists('chargeable_item_id', $payload)) {
                $updatePayload['chargeable_item_id'] = $this->nullableTrimmedValue($payload['chargeable_item_id']);
            }
            if (array_key_exists('gender_restriction', $payload)) {
                $updatePayload['gender_restriction'] = $this->nullableTrimmedValue($payload['gender_restriction']);
            }
        }

        $updated = $this->facilityResourceRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                facilityResourceId: $id,
                action: 'facility-resource.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'resourceType' => $resourceType,
                ],
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}

