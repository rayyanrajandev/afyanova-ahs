<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementReason;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Facades\DB;

class CorrectInventoryStockMovementUseCase
{
    public function __construct(
        private readonly InventoryStockMovementRepositoryInterface $inventoryStockMovementRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $movementId, array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $original = $this->inventoryStockMovementRepository->findById($movementId);
        if ($original === null) {
            throw new InventoryItemNotFoundException('Stock movement not found.');
        }

        if (! ($original['is_opening_stock'] ?? false)) {
            throw new InventoryStockOperationValidationException(
                'movementId',
                'Only opening stock entries can be corrected through this endpoint. Use reconciliation for regular movements.',
            );
        }

        $correctedQuantity = (float) ($payload['quantity'] ?? 0);
        if ($correctedQuantity <= 0) {
            throw new InventoryStockOperationValidationException(
                'quantity',
                'Corrected quantity must be greater than zero.',
            );
        }

        return DB::transaction(function () use ($original, $correctedQuantity, $payload, $movementId, $actorId): array {
            $originalQuantity = (float) ($original['quantity'] ?? 0);
            $difference = $correctedQuantity - $originalQuantity;

            if (abs($difference) < 0.001) {
                throw new InventoryStockOperationValidationException(
                    'quantity',
                    'Corrected quantity is the same as the original. No adjustment needed.',
                );
            }

            // Mark original as superseded
            InventoryStockMovementModel::query()
                ->whereKey($movementId)
                ->update(['superseded_by_id' => '__pending__']);

            // Create reversal movement
            $reversalPayload = [
                'item_id' => $original['item_id'],
                'movement_type' => 'adjust',
                'adjustment_direction' => $difference > 0 ? 'decrease' : 'increase',
                'quantity' => abs($difference),
                'reason' => 'Correction: ' . ($payload['reason'] ?? 'Audit correction'),
                'reason_code' => InventoryStockMovementReason::AUDIT_CORRECTION->value,
                'notes' => $payload['notes'] ?? 'Reversal of opening stock entry ' . $movementId,
                'occurred_at' => $payload['occurred_at'] ?? now()->toDateTimeString(),
                'metadata' => [
                    'correction' => true,
                    'corrected_movement_id' => $movementId,
                    'original_quantity' => $originalQuantity,
                    'corrected_quantity' => $correctedQuantity,
                ],
            ];

            if ($difference < 0) {
                // Need to reduce stock — issue
                $reversalPayload['adjustment_direction'] = 'decrease';
            }

            $this->inventoryBatchStockService->receiveMovement($reversalPayload, $actorId);

            // Create new correct movement
            $correctionPayload = [
                'item_id' => $original['item_id'],
                'movement_type' => 'receive',
                'is_opening_stock' => true,
                'quantity' => $correctedQuantity,
                'reason' => 'Corrected opening stock: ' . ($payload['reason'] ?? 'Audit correction'),
                'reason_code' => InventoryStockMovementReason::AUDIT_CORRECTION->value,
                'notes' => $payload['notes'] ?? 'Corrected replacement for opening stock entry ' . $movementId,
                'occurred_at' => $payload['occurred_at'] ?? now()->toDateTimeString(),
                'metadata' => [
                    'correction' => true,
                    'corrected_movement_id' => $movementId,
                    'original_quantity' => $originalQuantity,
                    'corrected_quantity' => $correctedQuantity,
                ],
            ];

            $result = $this->inventoryBatchStockService->receiveMovement($correctionPayload, $actorId);

            // Link superseded_by_id to the new movement
            InventoryStockMovementModel::query()
                ->whereKey($movementId)
                ->update(['superseded_by_id' => $result['id'] ?? null]);

            return $result;
        });
    }
}
