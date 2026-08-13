<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class ReconcileInventoryStockUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryStockMovementRepositoryInterface $inventoryStockMovementRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $itemId = (string) ($payload['item_id'] ?? '');
        $item = $this->inventoryItemRepository->findById($itemId);
        if (! $item) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        if ($this->usesBatchTracking($itemId, $item['category'] ?? null)) {
            if (! is_string($payload['batch_id'] ?? null) || trim((string) $payload['batch_id']) === '') {
                throw new InventoryStockOperationValidationException(
                    'batchId',
                    'Tracked inventory reconciliation must be recorded against the exact batch counted.',
                );
            }

            return $this->inventoryBatchStockService->reconcileBatchCount($payload, $actorId);
        }

        $expectedStock = (float) ($item['current_stock'] ?? 0);
        $countedStock = (float) $payload['counted_stock'];
        $varianceQuantity = $countedStock - $expectedStock;

        if (abs($varianceQuantity) < 0.0005) {
            throw new InventoryStockOperationValidationException(
                'countedStock',
                'No stock variance detected. Reconciliation entry was not created.',
            );
        }

        $adjustmentDirection = $varianceQuantity >= 0 ? 'increase' : 'decrease';
        $absoluteVariance = abs($varianceQuantity);

        $updatedItem = $this->inventoryItemRepository->update($itemId, [
            'current_stock' => $countedStock,
        ]);
        if (! $updatedItem) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $metadata = [
            'source' => 'stock_reconciliation',
            'scope' => 'item',
            'expectedStock' => $expectedStock,
            'countedStock' => $countedStock,
            'varianceQuantity' => $varianceQuantity,
            'sessionReference' => $payload['session_reference'] ?? null,
        ];

        $additionalMetadata = $payload['metadata'] ?? null;
        if (is_array($additionalMetadata)) {
            $metadata = array_merge($additionalMetadata, $metadata);
        }

        $movement = $this->inventoryStockMovementRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'item_id' => $itemId,
            'movement_type' => InventoryStockMovementType::ADJUST->value,
            'adjustment_direction' => $adjustmentDirection,
            'quantity' => $absoluteVariance,
            'quantity_delta' => $varianceQuantity,
            'stock_before' => $expectedStock,
            'stock_after' => $countedStock,
            'reason' => $payload['reason'] ?? 'Stock reconciliation',
            'notes' => $payload['notes'] ?? null,
            'actor_id' => $actorId,
            'metadata' => $metadata,
            'occurred_at' => $payload['occurred_at'] ?? now(),
            'created_at' => now(),
        ]);
        $movement['item'] = $updatedItem;

        return $movement;
    }

    private function usesBatchTracking(string $itemId, mixed $category): bool
    {
        $itemCategory = InventoryItemCategory::tryFrom((string) $category);
        if ($itemCategory?->requiresExpiryTracking() ?? false) {
            return true;
        }

        return InventoryBatchModel::query()
            ->where('item_id', $itemId)
            ->exists();
    }
}
