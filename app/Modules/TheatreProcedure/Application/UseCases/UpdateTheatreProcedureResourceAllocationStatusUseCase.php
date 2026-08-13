<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureResourceAllocationConflictException;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;

class UpdateTheatreProcedureResourceAllocationStatusUseCase
{
    public function __construct(
        private readonly TheatreProcedureResourceAllocationRepositoryInterface $resourceAllocationRepository,
        private readonly TheatreProcedureResourceAllocationAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $theatreProcedureId,
        string $allocationId,
        string $status,
        ?string $reason,
        ?string $actualStartAt,
        ?string $actualEndAt,
        ?int $actorId = null
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->resourceAllocationRepository->findByProcedureAndId($theatreProcedureId, $allocationId);
        if (! $existing) {
            return null;
        }

        $payload = [
            'status' => $status,
            'status_reason' => $reason,
        ];

        if ($status === TheatreProcedureResourceAllocationStatus::IN_USE->value) {
            $payload['actual_start_at'] = $actualStartAt ?? ($existing['actual_start_at'] ?? now());
            $payload['actual_end_at'] = null;
        }

        if ($status === TheatreProcedureResourceAllocationStatus::RELEASED->value) {
            $payload['actual_start_at'] = $actualStartAt ?? ($existing['actual_start_at'] ?? now());
            $payload['actual_end_at'] = $actualEndAt ?? now();
        }

        if ($status === TheatreProcedureResourceAllocationStatus::SCHEDULED->value) {
            $payload['actual_start_at'] = null;
            $payload['actual_end_at'] = null;
        }

        if ($status === TheatreProcedureResourceAllocationStatus::CANCELLED->value) {
            $payload['actual_end_at'] = null;
        }

        if (in_array($status, TheatreProcedureResourceAllocationStatus::overlapBlockingValues(), true)) {
            $hasConflict = $this->resourceAllocationRepository->hasOverlapForResource(
                resourceType: (string) ($existing['resource_type'] ?? ''),
                resourceReference: (string) ($existing['resource_reference'] ?? ''),
                plannedStartAt: (string) ($existing['planned_start_at'] ?? ''),
                plannedEndAt: (string) ($existing['planned_end_at'] ?? ''),
                tenantId: $existing['tenant_id'] ?? null,
                facilityId: $existing['facility_id'] ?? null,
                excludeAllocationId: $allocationId,
            );

            if ($hasConflict) {
                throw new TheatreProcedureResourceAllocationConflictException(
                    'Resource allocation status change would activate an overlapping allocation in the same scope and time window.',
                );
            }
        }

        $updated = $this->resourceAllocationRepository->update($allocationId, $payload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            allocationId: $allocationId,
            theatreProcedureId: $theatreProcedureId,
            action: 'theatre-procedure.resource-allocation.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
                'actual_start_at' => [
                    'before' => $existing['actual_start_at'] ?? null,
                    'after' => $updated['actual_start_at'] ?? null,
                ],
                'actual_end_at' => [
                    'before' => $existing['actual_end_at'] ?? null,
                    'after' => $updated['actual_end_at'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'release_end_time_required' => $status === TheatreProcedureResourceAllocationStatus::RELEASED->value,
                'release_end_time_provided' => ($updated['actual_end_at'] ?? null) !== null,
                'cancellation_reason_required' => $status === TheatreProcedureResourceAllocationStatus::CANCELLED->value,
                'cancellation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
