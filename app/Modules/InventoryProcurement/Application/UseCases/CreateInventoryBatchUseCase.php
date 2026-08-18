<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryBatchRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Str;

class CreateInventoryBatchUseCase
{
    public function __construct(
        private readonly InventoryBatchRepositoryInterface $batchRepository,
        private readonly InventoryItemRepositoryInterface $itemRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $item = $this->itemRepository->findById((string) $payload['item_id']);
        if (! $item) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $this->assertExpiryDateRecorded($item, $payload['expiry_date'] ?? null);

        // Inventory_MasterData_Alignment_Plan.md Phase 7: manufacturer is a
        // receipt-time fact -- generics are routinely sourced from different
        // manufacturers across purchase orders. Default to the item's manufacturer
        // preference when this receipt didn't specify one, rather than leaving it
        // blank; an explicit value on the batch always wins.
        $manufacturer = isset($payload['manufacturer']) ? trim((string) $payload['manufacturer']) : '';
        if ($manufacturer === '') {
            $manufacturer = trim((string) ($item['manufacturer'] ?? ''));
        }

        $internalBatchNumber = $this->generateInternalBatchNumber();

        return $this->batchRepository->create([
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'item_id' => $payload['item_id'],
            'internal_batch_number' => $internalBatchNumber,
            'batch_number' => isset($payload['batch_number']) ? strtoupper(trim((string) $payload['batch_number'])) : null,
            'lot_number' => isset($payload['lot_number']) ? trim((string) $payload['lot_number']) : null,
            'manufacture_date' => $payload['manufacture_date'] ?? null,
            'expiry_date' => $payload['expiry_date'] ?? null,
            'quantity' => (float) ($payload['quantity'] ?? 0),
            'warehouse_id' => $payload['warehouse_id'] ?? null,
            'bin_location' => $payload['bin_location'] ?? null,
            'supplier_id' => $payload['supplier_id'] ?? null,
            'manufacturer' => $manufacturer !== '' ? $manufacturer : null,
            'unit_cost' => isset($payload['unit_cost']) ? (float) $payload['unit_cost'] : null,
            'status' => 'available',
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    private function generateInternalBatchNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = 'BAT-'.$date.'-';
        $lastBatch = InventoryBatchModel::query()
            ->where('internal_batch_number', 'like', $prefix.'%')
            ->orderBy('internal_batch_number', 'desc')
            ->first();

        if ($lastBatch) {
            $lastSeq = (int) Str::after($lastBatch->internal_batch_number, $prefix);
            $nextSeq = $lastSeq + 1;
        } else {
            $nextSeq = 1;
        }

        return $prefix.str_pad((string) $nextSeq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Expiry-sensitive stock may not be batched without a date.
     *
     * InventoryBatchStockService already refuses this on the receiving path,
     * keyed on InventoryItemCategory::requiresExpiryTracking(). Creating a
     * batch directly went around that check and landed in the same tables, so
     * a medicine could still acquire an undated lot.
     *
     * An undated batch is not merely incomplete. batchState() sorts undated
     * batches *behind* every dated one and isBatchIssueEligible() treats them
     * as never expiring, so the stock settles at the back of the FEFO queue,
     * is never flagged, and ages out unissued while appearing available.
     *
     * The date is a manufacturer's fact on the pack; it cannot be derived, only
     * read and recorded. Hence a refusal rather than a default.
     *
     * @param  array<string, mixed>  $item
     */
    private function assertExpiryDateRecorded(array $item, mixed $expiryDate): void
    {
        $category = InventoryItemCategory::tryFrom((string) ($item['category'] ?? ''));

        if (! ($category?->requiresExpiryTracking() ?? false)) {
            return;
        }

        if (trim((string) ($expiryDate ?? '')) !== '') {
            return;
        }

        throw new InventoryStockOperationValidationException(
            'expiryDate',
            'Expiry date is required for expiry-sensitive stock. Read it from the pack — dispensing order depends on it.',
        );
    }
}
