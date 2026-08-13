<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureResourceAllocationConflictException;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;

class UpdateTheatreProcedureResourceAllocationUseCase
{
    public function __construct(
        private readonly TheatreProcedureResourceAllocationRepositoryInterface $resourceAllocationRepository,
        private readonly TheatreProcedureResourceAllocationAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $theatreProcedureId,
        string $allocationId,
        array $payload,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->resourceAllocationRepository->findByProcedureAndId($theatreProcedureId, $allocationId);
        if (! $existing) {
            return null;
        }

        $nextState = array_merge($existing, $payload);
        $nextStatus = (string) ($nextState['status'] ?? $existing['status'] ?? TheatreProcedureResourceAllocationStatus::SCHEDULED->value);

        if (in_array($nextStatus, TheatreProcedureResourceAllocationStatus::overlapBlockingValues(), true)) {
            $hasConflict = $this->resourceAllocationRepository->hasOverlapForResource(
                resourceType: (string) ($nextState['resource_type'] ?? ''),
                resourceReference: (string) ($nextState['resource_reference'] ?? ''),
                plannedStartAt: (string) ($nextState['planned_start_at'] ?? ''),
                plannedEndAt: (string) ($nextState['planned_end_at'] ?? ''),
                tenantId: $nextState['tenant_id'] ?? null,
                facilityId: $nextState['facility_id'] ?? null,
                excludeAllocationId: $allocationId,
            );

            if ($hasConflict) {
                throw new TheatreProcedureResourceAllocationConflictException(
                    'Resource allocation update overlaps with another active allocation in the same scope and time window.',
                );
            }
        }

        $updated = $this->resourceAllocationRepository->update($allocationId, $payload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                allocationId: $allocationId,
                theatreProcedureId: $theatreProcedureId,
                action: 'theatre-procedure.resource-allocation.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'resource_type',
            'resource_reference',
            'role_label',
            'planned_start_at',
            'planned_end_at',
            'actual_start_at',
            'actual_end_at',
            'status',
            'status_reason',
            'notes',
            'metadata',
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
