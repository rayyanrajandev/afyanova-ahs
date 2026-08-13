<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryWarehouseStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventoryWarehouseStatusUseCase
{
    public function __construct(
        private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository,
        private readonly InventoryWarehouseAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventoryWarehouseRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updated = $this->inventoryWarehouseRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = $status === InventoryWarehouseStatus::INACTIVE->value;

        $this->auditLogRepository->write(
            inventoryWarehouseId: $id,
            action: 'inventory-warehouse.status.updated',
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
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $reasonRequired,
                'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }
}
