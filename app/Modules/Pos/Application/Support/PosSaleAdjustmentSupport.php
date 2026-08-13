<?php

namespace App\Modules\Pos\Application\Support;

use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Pos\Domain\Repositories\PosSaleAdjustmentRepositoryInterface;
use Illuminate\Support\Str;
use RuntimeException;

class PosSaleAdjustmentSupport
{
    public function __construct(
        private readonly PosSaleAdjustmentRepositoryInterface $posSaleAdjustmentRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
    ) {}

    public function generateAdjustmentNumber(): string
    {
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $candidate = 'PSA'.now()->format('Ymd').strtoupper(Str::random(6));

            if (! $this->posSaleAdjustmentRepository->existsByAdjustmentNumber($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate a unique POS sale adjustment number.');
    }

    /**
     * @return array{cashAmount: float, nonCashAmount: float}
     */
    public function summarizeSalePaymentMix(array $sale): array
    {
        $cashAmount = 0.0;
        $nonCashAmount = 0.0;

        foreach (array_values(is_array($sale['payments'] ?? null) ? $sale['payments'] : []) as $payment) {
            $amountApplied = round((float) ($payment['amount_applied'] ?? 0), 2);
            $method = strtolower(trim((string) ($payment['payment_method'] ?? '')));

            if ($amountApplied <= 0) {
                continue;
            }

            if ($method === 'cash') {
                $cashAmount += $amountApplied;
            } else {
                $nonCashAmount += $amountApplied;
            }
        }

        return [
            'cashAmount' => round($cashAmount, 2),
            'nonCashAmount' => round($nonCashAmount, 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function restockPharmacyLineItems(
        array $sale,
        string $reason,
        string $notePrefix,
        string $adjustmentNumber,
        ?int $actorId = null,
    ): array {
        $stockMovements = [];

        foreach (array_values(is_array($sale['line_items'] ?? null) ? $sale['line_items'] : []) as $index => $lineItem) {
            if (($lineItem['item_type'] ?? null) !== 'pharmacy_item') {
                continue;
            }

            $inventoryItemId = trim((string) (($lineItem['item_reference'] ?? null) ?: ($lineItem['metadata']['inventoryItemId'] ?? null)));
            if ($inventoryItemId === '') {
                continue;
            }

            $issueMovements = InventoryStockMovementModel::query()
                ->where('movement_type', 'issue')
                ->where('metadata->source', 'pos.pharmacy_otc')
                ->where('metadata->pos_sale_id', $sale['id'] ?? null)
                ->where('metadata->pos_sale_line_id', $lineItem['id'] ?? null)
                ->orderBy('created_at')
                ->get();

            $batchAllocations = [];
            foreach ($issueMovements as $issueMovement) {
                $movementAllocations = $issueMovement->metadata['batchAllocations'] ?? [];
                if (is_array($movementAllocations)) {
                    $batchAllocations = array_merge($batchAllocations, $movementAllocations);
                }
            }

            $stockMovements[] = $this->inventoryBatchStockService->restockFromAllocations([
                'item_id' => $inventoryItemId,
                'quantity' => (float) ($lineItem['quantity'] ?? 0),
                'reason' => $reason,
                'notes' => sprintf(
                    '%s for POS sale %s.',
                    $notePrefix,
                    $sale['sale_number'] ?? 'POS sale',
                ),
                'metadata' => [
                    'source' => 'pos.sale_adjustment',
                    'pos_sale_id' => $sale['id'] ?? null,
                    'pos_sale_number' => $sale['sale_number'] ?? null,
                    'pos_receipt_number' => $sale['receipt_number'] ?? null,
                    'pos_sale_line_id' => $lineItem['id'] ?? null,
                    'pos_sale_adjustment_number' => $adjustmentNumber,
                    'approved_medicine_catalog_item_id' => $lineItem['metadata']['approvedMedicineCatalogItemId'] ?? null,
                    'inventory_item_id' => $inventoryItemId,
                    'line_index' => $index,
                ],
                'occurred_at' => now(),
            ], $batchAllocations, $actorId);
        }

        return $stockMovements;
    }
}
