<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInventoryStockMovementUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
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

        $movementType = (string) ($payload['movement_type'] ?? '');
        $adjustmentDirection = $payload['adjustment_direction'] ?? null;
        $usesBatchTracking = $this->usesBatchTracking($itemId, $item['category'] ?? null);

        if ($this->isReceiptLikeMovement($movementType, $adjustmentDirection)) {
            return $this->inventoryBatchStockService->receiveMovement($payload, $actorId);
        }

        if ($usesBatchTracking) {
            if (! is_string($payload['batch_id'] ?? null) || trim((string) $payload['batch_id']) === '') {
                throw new InventoryStockOperationValidationException(
                    'batchId',
                    'Tracked stock movements require the exact batch to be selected.',
                );
            }

            return $this->inventoryBatchStockService->issueExactBatch([
                ...$payload,
                'movement_type' => $movementType,
                'adjustment_direction' => $movementType === InventoryStockMovementType::ADJUST->value
                    ? ($adjustmentDirection ?? 'decrease')
                    : $adjustmentDirection,
            ], $actorId);
        }

        return $this->inventoryBatchStockService->issue([
            ...$payload,
            'movement_type' => $movementType,
            'adjustment_direction' => $movementType === InventoryStockMovementType::ADJUST->value
                ? ($adjustmentDirection ?? 'decrease')
                : $adjustmentDirection,
        ], $actorId);
    }

    private function isReceiptLikeMovement(string $movementType, ?string $adjustmentDirection): bool
    {
        return $movementType === InventoryStockMovementType::RECEIVE->value
            || (
                $movementType === InventoryStockMovementType::ADJUST->value
                && ($adjustmentDirection === null || $adjustmentDirection === 'increase')
            );
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
