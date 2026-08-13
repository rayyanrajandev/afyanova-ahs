<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Application\Exceptions\TheatreProcedureResourceAllocationConflictException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationAuditLogRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureResourceAllocationRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureResourceAllocationStatus;

class CreateTheatreProcedureResourceAllocationUseCase
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
        private readonly TheatreProcedureResourceAllocationRepositoryInterface $resourceAllocationRepository,
        private readonly TheatreProcedureResourceAllocationAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $theatreProcedureId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $procedure = $this->theatreProcedureRepository->findById($theatreProcedureId);
        if (! $procedure) {
            return null;
        }

        $status = $payload['status'] ?? TheatreProcedureResourceAllocationStatus::SCHEDULED->value;
        if (! in_array($status, TheatreProcedureResourceAllocationStatus::values(), true)) {
            $status = TheatreProcedureResourceAllocationStatus::SCHEDULED->value;
        }

        $createPayload = [
            'theatre_procedure_id' => $theatreProcedureId,
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'resource_type' => $payload['resource_type'],
            'resource_reference' => $payload['resource_reference'],
            'role_label' => $payload['role_label'] ?? null,
            'planned_start_at' => $payload['planned_start_at'],
            'planned_end_at' => $payload['planned_end_at'],
            'actual_start_at' => $status === TheatreProcedureResourceAllocationStatus::IN_USE->value
                ? ($payload['actual_start_at'] ?? now())
                : ($status === TheatreProcedureResourceAllocationStatus::RELEASED->value
                    ? ($payload['actual_start_at'] ?? now())
                    : null),
            'actual_end_at' => $status === TheatreProcedureResourceAllocationStatus::RELEASED->value
                ? ($payload['actual_end_at'] ?? now())
                : null,
            'status' => $status,
            'status_reason' => $payload['status_reason'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];

        if (in_array($status, TheatreProcedureResourceAllocationStatus::overlapBlockingValues(), true)) {
            $hasConflict = $this->resourceAllocationRepository->hasOverlapForResource(
                resourceType: (string) $createPayload['resource_type'],
                resourceReference: (string) $createPayload['resource_reference'],
                plannedStartAt: (string) $createPayload['planned_start_at'],
                plannedEndAt: (string) $createPayload['planned_end_at'],
                tenantId: $createPayload['tenant_id'],
                facilityId: $createPayload['facility_id'],
            );

            if ($hasConflict) {
                throw new TheatreProcedureResourceAllocationConflictException(
                    'Resource allocation overlaps with another active allocation in the same scope and time window.',
                );
            }
        }

        $created = $this->resourceAllocationRepository->create($createPayload);

        $this->auditLogRepository->write(
            allocationId: $created['id'],
            theatreProcedureId: $theatreProcedureId,
            action: 'theatre-procedure.resource-allocation.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        return $created;
    }
}
