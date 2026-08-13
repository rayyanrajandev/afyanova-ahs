<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Modules\InventoryProcurement\Domain\Repositories\DepartmentStockBalanceRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\DepartmentStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;

class DepartmentStockService
{
    public function __construct(
        private readonly DepartmentStockBalanceRepositoryInterface $balanceRepository,
        private readonly DepartmentStockMovementRepositoryInterface $movementRepository,
    ) {}

    /**
     * Record stock issued from a warehouse to a department.
     * Called when a department requisition is issued.
     */
    public function recordIssue(
        string $tenantId,
        ?string $facilityId,
        string $departmentId,
        string $itemId,
        float $quantity,
        ?string $batchId = null,
        ?string $unit = null,
        ?string $source = null,
        ?string $sourceId = null,
        ?int $actorId = null,
        ?string $notes = null,
    ): array {
        $balance = $this->balanceRepository->findOrCreateBalance(
            tenantId: $tenantId,
            departmentId: $departmentId,
            itemId: $itemId,
            batchId: $batchId,
            unit: $unit,
        );

        $quantityBefore = (float) $balance['quantity_on_hand'];

        $updatedBalance = $this->balanceRepository->incrementOnHand(
            balanceId: $balance['id'],
            quantity: $quantity,
            unit: $unit,
        );

        $quantityAfter = (float) $updatedBalance['quantity_on_hand'];

        $this->movementRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_stock_balance_id' => $balance['id'],
            'department_id' => $departmentId,
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'movement_type' => 'issue',
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source' => $source ?? 'department_requisition',
            'source_id' => $sourceId,
            'notes' => $notes,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);

        return $updatedBalance;
    }

    /**
     * Record consumption of department stock (e.g., pharmacy dispense, lab test).
     */
    public function recordConsumption(
        string $tenantId,
        ?string $facilityId,
        string $departmentId,
        string $itemId,
        float $quantity,
        ?string $batchId = null,
        ?string $source = null,
        ?string $sourceId = null,
        ?int $actorId = null,
        ?string $notes = null,
    ): ?array {
        $balance = $this->balanceRepository->findByDepartmentAndItem(
            departmentId: $departmentId,
            itemId: $itemId,
        );

        if (! $balance || (float) $balance['quantity_on_hand'] <= 0) {
            return null;
        }

        $quantityBefore = (float) $balance['quantity_on_hand'];

        $effectiveQuantity = min($quantity, $quantityBefore);

        $updatedBalance = $this->balanceRepository->recordConsumption(
            balanceId: $balance['id'],
            quantity: $effectiveQuantity,
        );

        $quantityAfter = (float) $updatedBalance['quantity_on_hand'];
        $internalBatchNumber = $this->resolveInternalBatchNumber($batchId);

        $this->movementRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_stock_balance_id' => $balance['id'],
            'department_id' => $departmentId,
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'internal_batch_number' => $internalBatchNumber,
            'movement_type' => 'consume',
            'quantity' => $effectiveQuantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source' => $source,
            'source_id' => $sourceId,
            'notes' => $notes,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);

        return $updatedBalance;
    }

    /**
     * Record return of stock from department to warehouse.
     */
    public function recordReturn(
        string $tenantId,
        ?string $facilityId,
        string $departmentId,
        string $itemId,
        float $quantity,
        ?string $batchId = null,
        ?int $actorId = null,
        ?string $notes = null,
    ): ?array {
        $balance = $this->balanceRepository->findByDepartmentAndItem(
            departmentId: $departmentId,
            itemId: $itemId,
        );

        if (! $balance) {
            return null;
        }

        $quantityBefore = (float) $balance['quantity_on_hand'];
        $effectiveQuantity = min($quantity, $quantityBefore);

        $updatedBalance = $this->balanceRepository->recordReturn(
            balanceId: $balance['id'],
            quantity: $effectiveQuantity,
        );

        $quantityAfter = (float) $updatedBalance['quantity_on_hand'];

        $this->movementRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_stock_balance_id' => $balance['id'],
            'department_id' => $departmentId,
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'movement_type' => 'return',
            'quantity' => $effectiveQuantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source' => 'return_to_store',
            'actor_id' => $actorId,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);

        return $updatedBalance;
    }

    /**
     * Record wastage/damage of department stock.
     */
    public function recordWastage(
        string $tenantId,
        ?string $facilityId,
        string $departmentId,
        string $itemId,
        float $quantity,
        ?string $batchId = null,
        ?int $actorId = null,
        ?string $notes = null,
    ): ?array {
        $balance = $this->balanceRepository->findByDepartmentAndItem(
            departmentId: $departmentId,
            itemId: $itemId,
        );

        if (! $balance) {
            return null;
        }

        $quantityBefore = (float) $balance['quantity_on_hand'];
        $effectiveQuantity = min($quantity, $quantityBefore);

        $updatedBalance = $this->balanceRepository->recordWastage(
            balanceId: $balance['id'],
            quantity: $effectiveQuantity,
        );

        $quantityAfter = (float) $updatedBalance['quantity_on_hand'];

        $this->movementRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_stock_balance_id' => $balance['id'],
            'department_id' => $departmentId,
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'movement_type' => 'waste',
            'quantity' => $effectiveQuantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'source' => 'wastage',
            'actor_id' => $actorId,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);

        return $updatedBalance;
    }

    public function getDepartmentBalance(string $departmentId, string $itemId): ?array
    {
        return $this->balanceRepository->findByDepartmentAndItem($departmentId, $itemId);
    }

    public function listDepartmentStock(
        string $departmentId,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        return $this->balanceRepository->listByDepartment($departmentId, $search, $page, $perPage);
    }

    public function departmentSummary(string $departmentId): array
    {
        return $this->balanceRepository->summaryByDepartment($departmentId);
    }

    private function resolveInternalBatchNumber(?string $batchId): ?string
    {
        if ($batchId === null) {
            return null;
        }

        $batch = InventoryBatchModel::query()->find($batchId);

        return $batch?->internal_batch_number;
    }
}
