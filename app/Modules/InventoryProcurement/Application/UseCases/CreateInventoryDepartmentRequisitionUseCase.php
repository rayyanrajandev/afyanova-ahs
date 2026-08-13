<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInventoryDepartmentRequisitionUseCase
{
    public function __construct(
        private readonly InventoryDepartmentRequisitionRepositoryInterface $requisitionRepository,
        private readonly InventoryDepartmentRequisitionLineRepositoryInterface $lineRepository,
        private readonly InventoryDepartmentRequisitionAuditLogRepositoryInterface $auditLogRepository,
        private readonly DepartmentRequisitionScopeResolver $departmentScopeResolver,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $requisition = $this->requisitionRepository->create([
            'requisition_number' => $this->requisitionRepository->nextRequisitionNumber(),
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'requesting_department' => trim((string) $payload['requesting_department']),
            'requesting_department_id' => $payload['requesting_department_id'] ?? null,
            'issuing_store' => $payload['issuing_store'] ?? null,
            'issuing_warehouse_id' => $payload['issuing_warehouse_id'] ?? null,
            'priority' => $payload['priority'] ?? 'normal',
            'status' => 'draft',
            'requested_by_user_id' => $actorId,
            'needed_by' => $payload['needed_by'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        $lines = [];
        foreach (($payload['lines'] ?? []) as $linePayload) {
            $this->departmentScopeResolver->assertItemIsRequestableForDepartment(
                itemId: (string) $linePayload['item_id'],
                departmentId: $payload['requesting_department_id'] ?? null,
            );

            $lines[] = $this->lineRepository->create([
                'requisition_id' => $requisition['id'],
                'item_id' => $linePayload['item_id'],
                'batch_id' => $linePayload['batch_id'] ?? null,
                'requested_quantity' => (float) $linePayload['requested_quantity'],
                'unit' => $linePayload['unit'],
                'notes' => $linePayload['notes'] ?? null,
            ]);
        }

        $requisition['lines'] = $lines;

        $this->auditLogRepository->write(
            requisitionId: $requisition['id'],
            action: 'department-requisition.created',
            actorId: $actorId,
            changes: ['after' => $requisition],
        );

        return $requisition;
    }
}
