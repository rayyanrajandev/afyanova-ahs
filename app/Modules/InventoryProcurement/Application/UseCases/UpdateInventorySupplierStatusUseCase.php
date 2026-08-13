<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventorySupplierStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInventorySupplierStatusUseCase
{
    public function __construct(
        private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository,
        private readonly InventorySupplierAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->inventorySupplierRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updated = $this->inventorySupplierRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
        ]);
        if (! $updated) {
            return null;
        }

        $reasonRequired = $status === InventorySupplierStatus::INACTIVE->value;

        $this->auditLogRepository->write(
            inventorySupplierId: $id,
            action: 'inventory-supplier.status.updated',
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
