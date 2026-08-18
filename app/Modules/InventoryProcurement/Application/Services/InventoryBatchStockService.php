<?php

namespace App\Modules\InventoryProcurement\Application\Services;

use App\Modules\InventoryProcurement\Application\Exceptions\InsufficientInventoryStockException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryProcurementReceiptValidationException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Domain\Services\InventoryUnitConversionService;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryStockMovementType;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryBatchStockService
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly InventoryStockReservationService $inventoryStockReservationService,
        private readonly InventoryUnitConversionService $unitConversionService,
    ) {}

    /**
     * @return array{
     *     trackingMode: string,
     *     hasBatchRecords: bool,
     *     availableQuantity: float,
     *     onHandQuantity: float,
     *     reservedQuantity: float,
     *     blockedQuantity: float,
     *     batchCount: int,
     *     validBatchCount: int,
     *     stockState: string
     * }
     */
    public function availability(string $itemId, mixed $occurredAt = null, ?string $warehouseId = null): array
    {
        $item = InventoryItemModel::query()->find($itemId);
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $resolvedWarehouseId = $this->resolvedWarehouseId($item, $warehouseId);
        $batchState = $this->batchState($item, $resolvedWarehouseId, $occurredAt, false);

        if (! $batchState['hasBatchRecords']) {
            $reservedQuantity = $this->inventoryStockReservationService->activeItemReservationQuantity($item->id);
            $availableQuantity = round(max((float) ($item->current_stock ?? 0) - $reservedQuantity, 0), 3);

            return [
                'trackingMode' => 'untracked',
                'hasBatchRecords' => false,
                'availableQuantity' => $availableQuantity,
                'onHandQuantity' => round((float) ($item->current_stock ?? 0), 3),
                'reservedQuantity' => $reservedQuantity,
                'blockedQuantity' => 0.0,
                'batchCount' => 0,
                'validBatchCount' => 0,
                'stockState' => $this->stockState($availableQuantity, (float) ($item->reorder_level ?? 0)),
                'availableBatches' => [],
            ];
        }

        $availableBatches = $batchState['validBatches']->map(
            static fn (InventoryBatchModel $b): array => [
                'id' => $b->id,
                'internal_batch_number' => $b->internal_batch_number,
                'batch_number' => $b->batch_number,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'quantity' => (float) ($b->quantity ?? 0),
                'reserved' => (float) ($batchState['reservedByBatch'][(string) $b->id] ?? 0),
                'available' => max((float) ($b->quantity ?? 0) - (float) ($batchState['reservedByBatch'][(string) $b->id] ?? 0), 0),
            ],
        )->values()->all();

        return [
            'trackingMode' => 'tracked',
            'hasBatchRecords' => true,
            'availableQuantity' => $batchState['availableQuantity'],
            'onHandQuantity' => round((float) ($item->current_stock ?? 0), 3),
            'reservedQuantity' => $batchState['reservedQuantity'],
            'blockedQuantity' => $batchState['blockedQuantity'],
            'batchCount' => $batchState['batchCount'],
            'validBatchCount' => $batchState['validBatchCount'],
            'stockState' => $this->stockState($batchState['availableQuantity'], (float) ($item->reorder_level ?? 0)),
            'availableBatches' => $availableBatches,
        ];
    }

    /**
     * Merges an inventory item array (as returned by
     * InventoryItemRepositoryInterface::findBestActiveMatchByCodeOrName())
     * with its reservation/FEFO-aware availability, so callers get a
     * single array carrying both raw on-hand stock and the real
     * dispensable quantity. Shared by any consumer that needs "is there
     * actually enough of this to fulfil" rather than a raw stock count.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public function enrichItemAvailability(array $item): array
    {
        $availability = $this->availability((string) $item['id'], now(), $item['default_warehouse_id'] ?? null);

        return array_merge($item, [
            'available_stock' => $availability['availableQuantity'] ?? ($item['current_stock'] ?? null),
            'stock_state' => $availability['stockState'] ?? null,
            'batch_tracking_mode' => $availability['trackingMode'] ?? 'untracked',
            'blocked_batch_quantity' => $availability['blockedQuantity'] ?? 0,
            // availability() computes the FEFO-ordered batch list and this
            // dropped it, so PharmacyMedicationAvailabilityResponseTransformer
            // -- the only reader of `available_batches` anywhere -- always found
            // nothing. Pharmacy's lot selector could therefore never render, at
            // any stock level, and every dispense fell through to the automatic
            // FEFO pick with issueExactBatch() unreachable from the workspace.
            //
            // Renamed on the way through on purpose: this array is consumed in
            // snake_case by that transformer, while availability() speaks
            // camelCase to its own HTTP callers.
            'available_batches' => $availability['availableBatches'] ?? [],
            'has_batch_records' => $availability['hasBatchRecords'] ?? false,
            'valid_batch_count' => $availability['validBatchCount'] ?? 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function issue(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): array {
            $itemId = trim((string) ($payload['item_id'] ?? ''));
            $quantity = round((float) ($payload['quantity'] ?? 0), 3);

            if ($itemId === '') {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($quantity <= 0) {
                throw new InsufficientInventoryStockException('Issue quantity must be greater than zero.');
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $conversion = $this->unitConversionService->toBaseQuantity(
                $item->id,
                $quantity,
                $this->stringOrNull($payload['unit_id'] ?? null),
                $this->stringOrNull($payload['unit'] ?? null),
            );

            $baseQuantity = $conversion['base_quantity'];
            $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);
            $warehouseId = $this->resolvedWarehouseId($item, $payload['source_warehouse_id'] ?? null);
            $reservationExclusionIds = $this->reservationExclusionIds($payload);
            $batchState = $this->batchState($item, $warehouseId, $occurredAt, true, $reservationExclusionIds);
            $movementType = InventoryStockMovementType::tryFrom((string) ($payload['movement_type'] ?? ''))
                ?? InventoryStockMovementType::ISSUE;
            $adjustmentDirection = $this->stringOrNull($payload['adjustment_direction'] ?? null);

            if ($batchState['hasBatchRecords']) {
                if ($batchState['availableQuantity'] < $baseQuantity) {
                    throw new InsufficientInventoryStockException('Valid FEFO batch stock is insufficient for this issue.');
                }

                $allocations = $this->allocateAcrossBatches(
                    $batchState['validBatches'],
                    $baseQuantity,
                    $batchState['reservedByBatch'],
                );
            } else {
                $availableQuantity = round(max(
                    (float) ($item->current_stock ?? 0)
                        - $this->inventoryStockReservationService->activeItemReservationQuantity($item->id, $reservationExclusionIds),
                    0
                ), 3);

                if ($availableQuantity < $baseQuantity) {
                    throw new InsufficientInventoryStockException('Unreserved stock is insufficient for this issue.');
                }

                $allocations = [];
            }

            $stockBefore = round((float) ($item->current_stock ?? 0), 3);
            $stockAfter = round($stockBefore - $baseQuantity, 3);
            if ($stockAfter < 0) {
                throw new InsufficientInventoryStockException('Stock movement would result in negative stock.');
            }

            $item->forceFill(['current_stock' => $stockAfter])->save();

            $movement = InventoryStockMovementModel::query()->create([
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => count($allocations) === 1 ? ($allocations[0]['batchId'] ?? null) : null,
                'internal_batch_number' => count($allocations) === 1 ? ($allocations[0]['internalBatchNumber'] ?? null) : null,
                'procurement_request_id' => $payload['procurement_request_id'] ?? null,
                'source_supplier_id' => $payload['source_supplier_id'] ?? null,
                'source_warehouse_id' => $warehouseId,
                'destination_warehouse_id' => $payload['destination_warehouse_id'] ?? null,
                'destination_department_id' => $payload['destination_department_id'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'clinical_catalog_item_id' => $payload['clinical_catalog_item_id'] ?? null,
                'consumption_recipe_item_id' => $payload['consumption_recipe_item_id'] ?? null,
                'movement_type' => $movementType->value,
                'adjustment_direction' => $movementType === InventoryStockMovementType::ADJUST
                    ? ($adjustmentDirection ?? 'decrease')
                    : $adjustmentDirection,
                'quantity' => $baseQuantity,
                'quantity_delta' => -1 * $baseQuantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $payload['reason'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'actor_id' => $actorId,
                'metadata' => $this->mergedMetadata(
                    array_merge($payload['metadata'] ?? [], $conversion),
                    $allocations === []
                        ? ['batchMode' => 'untracked']
                        : [
                            'batchMode' => 'tracked',
                            'issuePolicy' => 'fefo',
                            'batchAllocationCount' => count($allocations),
                            'batchAllocations' => $allocations,
                        ],
                ),
                'requested_quantity' => $conversion['requested_quantity'],
                'requested_unit' => $conversion['requested_unit'],
                'requested_unit_id' => $conversion['requested_unit_id'],
                'base_unit' => $conversion['base_unit'],
                'base_quantity' => $conversion['base_quantity'],
                'conversion_factor' => $conversion['conversion_factor'],
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $movementArray = $movement->toArray();
            $movementArray['item'] = $item->fresh()?->toArray();

            return $movementArray;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function issueExactBatch(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): array {
            $itemId = trim((string) ($payload['item_id'] ?? ''));
            $batchId = $this->stringOrNull($payload['batch_id'] ?? null);
            $quantity = round((float) ($payload['quantity'] ?? 0), 3);
            $quantityField = $this->stringOrNull($payload['quantity_field'] ?? null) ?? 'quantity';
            $batchField = $this->stringOrNull($payload['batch_field'] ?? null) ?? 'batchId';

            if ($itemId === '') {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($batchId === null) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Select the exact batch for this tracked stock movement.',
                );
            }

            if ($quantity <= 0) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $quantityField,
                    'Issue quantity must be greater than zero.',
                );
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $conversion = $this->unitConversionService->toBaseQuantity(
                $item->id,
                $quantity,
                $this->stringOrNull($payload['unit_id'] ?? null),
                $this->stringOrNull($payload['unit'] ?? null),
            );

            $baseQuantity = $conversion['base_quantity'];

            $warehouseId = $this->resolvedWarehouseId($item, $payload['source_warehouse_id'] ?? null);
            $batch = InventoryBatchModel::query()
                ->whereKey($batchId)
                ->where('item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if (! $batch instanceof InventoryBatchModel) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Selected batch was not found for this inventory item.',
                );
            }

            if ($warehouseId !== null && $batch->warehouse_id !== $warehouseId) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Selected batch is not stocked in the chosen source warehouse.',
                );
            }

            $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);
            $reservationExclusionIds = $this->reservationExclusionIds($payload);
            if (! $this->isBatchIssueEligible($batch, $occurredAt)) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Selected batch is not available for issue. Use an available, non-expired batch.',
                );
            }

            $reservedQuantity = $this->inventoryStockReservationService->activeBatchReservationQuantity(
                $batch->id,
                $reservationExclusionIds,
            );
            $availableInBatch = round(max((float) ($batch->quantity ?? 0) - $reservedQuantity, 0), 3);
            if ($availableInBatch < $baseQuantity) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $quantityField,
                    'Selected batch does not have enough available stock.',
                );
            }

            $stockBefore = round((float) ($item->current_stock ?? 0), 3);
            $stockAfter = round($stockBefore - $baseQuantity, 3);
            if ($stockAfter < 0) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $quantityField,
                    'Stock movement would result in negative stock.',
                );
            }

            $batch->forceFill([
                'quantity' => round($availableInBatch - $baseQuantity, 3),
            ])->save();

            $item->forceFill(['current_stock' => $stockAfter])->save();

            $movementType = InventoryStockMovementType::tryFrom((string) ($payload['movement_type'] ?? ''))
                ?? InventoryStockMovementType::ISSUE;
            $adjustmentDirection = $this->stringOrNull($payload['adjustment_direction'] ?? null);
            $allocation = [
                'batchId' => (string) $batch->id,
                'internalBatchNumber' => $batch->internal_batch_number,
                'batchNumber' => $batch->batch_number,
                'lotNumber' => $batch->lot_number,
                'expiryDate' => $batch->expiry_date?->toDateString(),
                'warehouseId' => $batch->warehouse_id,
                'quantity' => $baseQuantity,
            ];

            $movement = InventoryStockMovementModel::query()->create([
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => $batch->id,
                'internal_batch_number' => $batch->internal_batch_number,
                'procurement_request_id' => $payload['procurement_request_id'] ?? null,
                'source_supplier_id' => $payload['source_supplier_id'] ?? null,
                'source_warehouse_id' => $warehouseId,
                'destination_warehouse_id' => $payload['destination_warehouse_id'] ?? null,
                'destination_department_id' => $payload['destination_department_id'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'clinical_catalog_item_id' => $payload['clinical_catalog_item_id'] ?? null,
                'consumption_recipe_item_id' => $payload['consumption_recipe_item_id'] ?? null,
                'movement_type' => $movementType->value,
                'adjustment_direction' => $movementType === InventoryStockMovementType::ADJUST
                    ? ($adjustmentDirection ?? 'decrease')
                    : $adjustmentDirection,
                'quantity' => $baseQuantity,
                'quantity_delta' => -1 * $baseQuantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $payload['reason'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'actor_id' => $actorId,
                'metadata' => $this->mergedMetadata(
                    array_merge($payload['metadata'] ?? [], $conversion),
                    [
                        'batchMode' => 'tracked',
                        'issuePolicy' => 'exact_batch',
                        'batchAllocationCount' => 1,
                        'batchAllocations' => [$allocation],
                    ],
                ),
                'requested_quantity' => $conversion['requested_quantity'],
                'requested_unit' => $conversion['requested_unit'],
                'requested_unit_id' => $conversion['requested_unit_id'],
                'base_unit' => $conversion['base_unit'],
                'base_quantity' => $conversion['base_quantity'],
                'conversion_factor' => $conversion['conversion_factor'],
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $movementArray = $movement->toArray();
            $movementArray['item'] = $item->fresh()?->toArray();
            $movementArray['batch'] = $batch->fresh()?->toArray();

            return $movementArray;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $batchAllocations
     * @return array<string, mixed>
     */
    public function restockFromAllocations(array $payload, array $batchAllocations = [], ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $batchAllocations, $actorId): array {
            $itemId = trim((string) ($payload['item_id'] ?? ''));
            $quantity = round((float) ($payload['quantity'] ?? 0), 3);

            if ($itemId === '') {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($quantity <= 0) {
                throw new InsufficientInventoryStockException('Restock quantity must be greater than zero.');
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $conversion = $this->unitConversionService->toBaseQuantity(
                $item->id,
                $quantity,
                $this->stringOrNull($payload['unit_id'] ?? null),
                $this->stringOrNull($payload['unit'] ?? null),
            );

            $baseQuantity = $conversion['base_quantity'];

            $resolvedAllocations = $this->normalizeBatchAllocations($batchAllocations);
            if ($resolvedAllocations !== []) {
                foreach ($resolvedAllocations as $allocation) {
                    $batchId = $this->stringOrNull($allocation['batchId'] ?? null);
                    if ($batchId === null) {
                        continue;
                    }

                    $batch = InventoryBatchModel::query()
                        ->whereKey($batchId)
                        ->where('item_id', $item->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $batch instanceof InventoryBatchModel) {
                        throw new InventoryItemNotFoundException('Tracked inventory batch could not be restored.');
                    }

                    $batch->forceFill([
                        'quantity' => round((float) ($batch->quantity ?? 0) + (float) ($allocation['quantity'] ?? 0), 3),
                    ])->save();
                }
            }

            $stockBefore = round((float) ($item->current_stock ?? 0), 3);
            $stockAfter = round($stockBefore + $baseQuantity, 3);
            $item->forceFill(['current_stock' => $stockAfter])->save();

            $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);
            $movement = InventoryStockMovementModel::query()->create([
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => count($resolvedAllocations) === 1 ? ($resolvedAllocations[0]['batchId'] ?? null) : null,
                'procurement_request_id' => $payload['procurement_request_id'] ?? null,
                'source_supplier_id' => $payload['source_supplier_id'] ?? null,
                'source_warehouse_id' => $payload['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $payload['destination_warehouse_id'] ?? null,
                'destination_department_id' => $payload['destination_department_id'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'clinical_catalog_item_id' => $payload['clinical_catalog_item_id'] ?? null,
                'consumption_recipe_item_id' => $payload['consumption_recipe_item_id'] ?? null,
                'movement_type' => InventoryStockMovementType::ADJUST->value,
                'adjustment_direction' => 'increase',
                'quantity' => $baseQuantity,
                'quantity_delta' => $baseQuantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $payload['reason'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'actor_id' => $actorId,
                'metadata' => $this->mergedMetadata(
                    array_merge($payload['metadata'] ?? [], $conversion),
                    $resolvedAllocations === []
                        ? ['batchMode' => 'untracked']
                        : [
                            'batchMode' => 'tracked',
                            'batchAllocationCount' => count($resolvedAllocations),
                            'batchAllocations' => $resolvedAllocations,
                        ],
                ),
                'requested_quantity' => $conversion['requested_quantity'],
                'requested_unit' => $conversion['requested_unit'],
                'requested_unit_id' => $conversion['requested_unit_id'],
                'base_unit' => $conversion['base_unit'],
                'base_quantity' => $conversion['base_quantity'],
                'conversion_factor' => $conversion['conversion_factor'],
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $movementArray = $movement->toArray();
            $movementArray['item'] = $item->fresh()?->toArray();

            return $movementArray;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function receive(array $payload, ?int $actorId = null): array
    {
        return $this->performReceive(
            payload: $payload,
            actorId: $actorId,
            quantityField: 'receivedQuantity',
            validationExceptionClass: InventoryProcurementReceiptValidationException::class,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function receiveMovement(array $payload, ?int $actorId = null): array
    {
        return $this->performReceive(
            payload: $payload,
            actorId: $actorId,
            quantityField: $this->stringOrNull($payload['quantity_field'] ?? null) ?? 'quantity',
            validationExceptionClass: InventoryStockOperationValidationException::class,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reconcileBatchCount(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId): array {
            $itemId = trim((string) ($payload['item_id'] ?? ''));
            $batchId = $this->stringOrNull($payload['batch_id'] ?? null);
            $countedBatchQuantity = round((float) ($payload['counted_batch_quantity'] ?? 0), 3);
            $countedField = $this->stringOrNull($payload['counted_field'] ?? null) ?? 'countedBatchQuantity';
            $batchField = $this->stringOrNull($payload['batch_field'] ?? null) ?? 'batchId';

            if ($itemId === '') {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($batchId === null) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Select the exact batch being reconciled.',
                );
            }

            if ($countedBatchQuantity < 0) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $countedField,
                    'Counted batch quantity must be zero or greater.',
                );
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $batch = InventoryBatchModel::query()
                ->whereKey($batchId)
                ->where('item_id', $item->id)
                ->lockForUpdate()
                ->first();

            if (! $batch instanceof InventoryBatchModel) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $batchField,
                    'Selected batch was not found for this inventory item.',
                );
            }

            $batchQuantityBefore = round((float) ($batch->quantity ?? 0), 3);
            $varianceQuantity = round($countedBatchQuantity - $batchQuantityBefore, 3);
            if (abs($varianceQuantity) < 0.0005) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $countedField,
                    'No stock variance detected. Reconciliation entry was not created.',
                );
            }

            $stockBefore = round((float) ($item->current_stock ?? 0), 3);
            $stockAfter = round($stockBefore + $varianceQuantity, 3);
            if ($stockAfter < 0) {
                $this->throwValidationException(
                    InventoryStockOperationValidationException::class,
                    $countedField,
                    'Batch reconciliation would result in negative item stock.',
                );
            }

            $batch->forceFill([
                'quantity' => $countedBatchQuantity,
            ])->save();

            $item->forceFill([
                'current_stock' => $stockAfter,
            ])->save();

            $movement = InventoryStockMovementModel::query()->create([
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => $batch->id,
                'internal_batch_number' => $batch->internal_batch_number,
                'source_supplier_id' => null,
                'source_warehouse_id' => $batch->warehouse_id,
                'destination_warehouse_id' => $batch->warehouse_id,
                'destination_department_id' => null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'clinical_catalog_item_id' => $payload['clinical_catalog_item_id'] ?? null,
                'consumption_recipe_item_id' => $payload['consumption_recipe_item_id'] ?? null,
                'movement_type' => InventoryStockMovementType::ADJUST->value,
                'adjustment_direction' => $varianceQuantity >= 0 ? 'increase' : 'decrease',
                'quantity' => abs($varianceQuantity),
                'quantity_delta' => $varianceQuantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $payload['reason'] ?? 'Stock reconciliation',
                'notes' => $payload['notes'] ?? null,
                'actor_id' => $actorId,
                'metadata' => $this->mergedMetadata(
                    $payload['metadata'] ?? null,
                    [
                        'source' => 'stock_reconciliation',
                        'scope' => 'batch',
                        'expectedStock' => $batchQuantityBefore,
                        'countedStock' => $countedBatchQuantity,
                        'varianceQuantity' => $varianceQuantity,
                        'itemStockBefore' => $stockBefore,
                        'itemStockAfter' => $stockAfter,
                        'sessionReference' => $payload['session_reference'] ?? null,
                        'batchMode' => 'tracked',
                        'batchId' => (string) $batch->id,
                        'batchNumber' => $batch->batch_number,
                        'warehouseId' => $batch->warehouse_id,
                    ],
                ),
                'occurred_at' => $this->normalizeOccurredAt($payload['occurred_at'] ?? null),
                'created_at' => now(),
            ]);

            $movementArray = $movement->toArray();
            $movementArray['item'] = $item->fresh()?->toArray();
            $movementArray['batch'] = $batch->fresh()?->toArray();

            return $movementArray;
        });
    }

    private function resolvedWarehouseId(InventoryItemModel $item, mixed $warehouseId): ?string
    {
        $resolved = $this->stringOrNull($warehouseId);

        return $resolved ?? $this->stringOrNull($item->default_warehouse_id ?? null);
    }

    /**
     * @return array{
     *     hasBatchRecords: bool,
     *     availableQuantity: float,
     *     reservedQuantity: float,
     *     blockedQuantity: float,
     *     batchCount: int,
     *     validBatchCount: int,
     *     validBatches: Collection<int, InventoryBatchModel>,
     *     reservedByBatch: array<string, float>
     * }
     */
    private function batchState(
        InventoryItemModel $item,
        ?string $warehouseId,
        mixed $occurredAt,
        bool $forUpdate,
        array $excludeReservationIds = [],
    ): array {
        $asOf = $this->normalizeOccurredAt($occurredAt);

        $allBatchQuery = InventoryBatchModel::query()
            ->where('item_id', $item->id);

        $hasBatchRecords = (clone $allBatchQuery)->exists();

        if (! $hasBatchRecords) {
            return [
                'hasBatchRecords' => false,
                'availableQuantity' => 0.0,
                'reservedQuantity' => 0.0,
                'blockedQuantity' => 0.0,
                'batchCount' => 0,
                'validBatchCount' => 0,
                'validBatches' => collect(),
                'reservedByBatch' => [],
            ];
        }

        $scopedQuery = InventoryBatchModel::query()
            ->where('item_id', $item->id);

        if ($warehouseId !== null) {
            $scopedQuery->where('warehouse_id', $warehouseId);
        }

        if ($forUpdate) {
            $scopedQuery->lockForUpdate();
        }

        $batches = $scopedQuery
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $validBatches = $batches->filter(fn (InventoryBatchModel $batch): bool => $this->isBatchIssueEligible($batch, $asOf))
            ->values();

        $reservedByBatch = $this->inventoryStockReservationService->activeBatchReservationQuantities(
            $validBatches->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all(),
            $excludeReservationIds,
        );

        $availableQuantity = round((float) $validBatches->sum(
            static fn (InventoryBatchModel $batch): float => max(
                (float) ($batch->quantity ?? 0) - (float) ($reservedByBatch[(string) $batch->id] ?? 0),
                0
            )
        ), 3);
        $reservedQuantity = round((float) array_sum($reservedByBatch), 3);

        $blockedQuantity = round((float) $batches->reject(
            fn (InventoryBatchModel $batch): bool => $this->isBatchIssueEligible($batch, $asOf)
        )->sum(
            static fn (InventoryBatchModel $batch): float => max((float) ($batch->quantity ?? 0), 0)
        ), 3);

        return [
            'hasBatchRecords' => true,
            'availableQuantity' => $availableQuantity,
            'reservedQuantity' => $reservedQuantity,
            'blockedQuantity' => $blockedQuantity,
            'batchCount' => $batches->count(),
            'validBatchCount' => $validBatches->count(),
            'validBatches' => $validBatches,
            'reservedByBatch' => $reservedByBatch,
        ];
    }

    private function isBatchIssueEligible(InventoryBatchModel $batch, Carbon $asOf): bool
    {
        if ((string) ($batch->status ?? '') !== 'available') {
            return false;
        }

        $quantity = round((float) ($batch->quantity ?? 0), 3);
        if ($quantity <= 0) {
            return false;
        }

        $expiryDate = $batch->expiry_date;
        if ($expiryDate === null) {
            return true;
        }

        $expiry = $expiryDate instanceof Carbon
            ? $expiryDate->copy()
            : Carbon::parse((string) $expiryDate);

        return ! $expiry->endOfDay()->lt($asOf);
    }

    /**
     * @param  Collection<int, InventoryBatchModel>  $batches
     * @return array<int, array<string, mixed>>
     */
    private function allocateAcrossBatches(Collection $batches, float $requiredQuantity, array $reservedByBatch = []): array
    {
        $remaining = round($requiredQuantity, 3);
        $allocations = [];

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $availableInBatch = round(max(
                (float) ($batch->quantity ?? 0) - (float) ($reservedByBatch[(string) $batch->id] ?? 0),
                0
            ), 3);
            if ($availableInBatch <= 0) {
                continue;
            }

            $issuedQuantity = round(min($availableInBatch, $remaining), 3);
            if ($issuedQuantity <= 0) {
                continue;
            }

            $batch->forceFill([
                'quantity' => round($availableInBatch - $issuedQuantity, 3),
            ])->save();

            $allocations[] = [
                'batchId' => (string) $batch->id,
                'internalBatchNumber' => $batch->internal_batch_number,
                'batchNumber' => $batch->batch_number,
                'lotNumber' => $batch->lot_number,
                'expiryDate' => $batch->expiry_date?->toDateString(),
                'warehouseId' => $batch->warehouse_id,
                'quantity' => $issuedQuantity,
            ];

            $remaining = round($remaining - $issuedQuantity, 3);
        }

        if ($remaining > 0) {
            throw new InsufficientInventoryStockException('Valid FEFO batch stock is insufficient for this issue.');
        }

        return $allocations;
    }

    /**
     * @param  array<int, array<string, mixed>>  $batchAllocations
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBatchAllocations(array $batchAllocations): array
    {
        $normalized = [];

        foreach ($batchAllocations as $allocation) {
            $quantity = round((float) ($allocation['quantity'] ?? 0), 3);
            if ($quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'batchId' => $this->stringOrNull($allocation['batchId'] ?? null),
                'internalBatchNumber' => $allocation['internalBatchNumber'] ?? null,
                'batchNumber' => $allocation['batchNumber'] ?? null,
                'lotNumber' => $allocation['lotNumber'] ?? null,
                'expiryDate' => $allocation['expiryDate'] ?? null,
                'warehouseId' => $allocation['warehouseId'] ?? null,
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private function normalizeOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null) {
            return now();
        }

        return Carbon::parse((string) $value);
    }

    private function normalizeDateOrNull(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value)->startOfDay();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergedMetadata(mixed $metadata, array $extra): array
    {
        $base = is_array($metadata) ? $metadata : [];

        return array_merge($base, $extra);
    }

    private function stockState(float $availableQuantity, float $reorderLevel): string
    {
        if ($availableQuantity <= 0) {
            return 'out_of_stock';
        }

        if ($availableQuantity <= round(max($reorderLevel, 0), 3)) {
            return 'low_stock';
        }

        return 'healthy';
    }

    private function stringOrNull(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
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

    private function normalizeBatchNumber(mixed $value): ?string
    {
        $normalized = $this->stringOrNull($value);

        return $normalized === null ? null : strtoupper($normalized);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function reservationExclusionIds(array $payload): array
    {
        $providedIds = collect($payload['reservation_exclusion_ids'] ?? [])
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();

        if ($providedIds !== []) {
            return $providedIds;
        }

        $sourceType = $this->stringOrNull($payload['reservation_source_type'] ?? null);
        $sourceId = $this->stringOrNull($payload['reservation_source_id'] ?? null);
        if ($sourceType === null || $sourceId === null) {
            return [];
        }

        return $this->inventoryStockReservationService->activeReservationIdsForSource(
            $sourceType,
            $sourceId,
            $this->stringOrNull($payload['reservation_source_line_id'] ?? null),
        );
    }

    /**
     * @param  class-string<\RuntimeException>  $validationExceptionClass
     */
    private function assertReceiptBatchCompatibility(
        InventoryBatchModel $batch,
        ?string $lotNumber,
        ?Carbon $manufactureDate,
        ?Carbon $expiryDate,
        string $validationExceptionClass,
    ): void {
        if ((string) ($batch->status ?? '') !== 'available') {
            $this->throwValidationException(
                $validationExceptionClass,
                'batchNumber',
                'The selected batch is not available for receiving. Resolve the batch status before posting more stock.',
            );
        }

        $existingLotNumber = $this->stringOrNull($batch->lot_number);
        if ($existingLotNumber !== null && $lotNumber !== null && strcasecmp($existingLotNumber, $lotNumber) !== 0) {
            $this->throwValidationException(
                $validationExceptionClass,
                'lotNumber',
                'Lot number does not match the existing batch record for this warehouse.',
            );
        }

        $existingManufactureDate = $batch->manufacture_date instanceof Carbon
            ? $batch->manufacture_date->copy()->startOfDay()
            : ($batch->manufacture_date !== null ? Carbon::parse((string) $batch->manufacture_date)->startOfDay() : null);

        if ($existingManufactureDate !== null && $manufactureDate !== null && ! $existingManufactureDate->equalTo($manufactureDate)) {
            $this->throwValidationException(
                $validationExceptionClass,
                'manufactureDate',
                'Manufacture date does not match the existing batch record for this warehouse.',
            );
        }

        $existingExpiryDate = $batch->expiry_date instanceof Carbon
            ? $batch->expiry_date->copy()->startOfDay()
            : ($batch->expiry_date !== null ? Carbon::parse((string) $batch->expiry_date)->startOfDay() : null);

        if ($existingExpiryDate !== null && $expiryDate !== null && ! $existingExpiryDate->equalTo($expiryDate)) {
            $this->throwValidationException(
                $validationExceptionClass,
                'expiryDate',
                'Expiry date does not match the existing batch record for this warehouse.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  class-string<\RuntimeException>  $validationExceptionClass
     * @return array<string, mixed>
     */
    private function performReceive(
        array $payload,
        ?int $actorId,
        string $quantityField,
        string $validationExceptionClass,
    ): array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        return DB::transaction(function () use ($payload, $actorId, $quantityField, $validationExceptionClass): array {
            $itemId = trim((string) ($payload['item_id'] ?? ''));
            $quantity = round((float) ($payload['quantity'] ?? 0), 6);

            if ($itemId === '') {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            if ($quantity <= 0) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    $quantityField,
                    'Received quantity must be greater than zero.',
                );
            }

            $item = InventoryItemModel::query()
                ->whereKey($itemId)
                ->lockForUpdate()
                ->first();

            if (! $item instanceof InventoryItemModel) {
                throw new InventoryItemNotFoundException('Inventory item not found.');
            }

            $conversion = $this->unitConversionService->toBaseQuantity(
                $item->id,
                round((float) ($payload['quantity'] ?? 0), 6),
                $this->stringOrNull($payload['unit_id'] ?? null),
                $this->stringOrNull($payload['unit'] ?? null),
            );

            $baseQuantity = $conversion['base_quantity'];

            $occurredAt = $this->normalizeOccurredAt($payload['occurred_at'] ?? null);
            $warehouseId = $this->resolvedWarehouseId($item, $payload['destination_warehouse_id'] ?? null);
            $category = InventoryItemCategory::tryFrom((string) ($item->category ?? ''));
            $requiresExpiryTracking = $category?->requiresExpiryTracking() ?? false;

            $allBatchQuery = InventoryBatchModel::query()
                ->where('item_id', $item->id);

            $hasBatchRecords = (clone $allBatchQuery)->exists();
            $existingBatchQuantity = round((float) (clone $allBatchQuery)->sum('quantity'), 3);
            $currentStock = round((float) ($item->current_stock ?? 0), 3);

            if ($hasBatchRecords && abs($currentStock - $existingBatchQuantity) > 0.001) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    'batchNumber',
                    'Batch ledger is out of balance with item stock. Reconcile existing batches before receiving more stock.',
                );
            }

            if ($requiresExpiryTracking && ! $hasBatchRecords && $currentStock > 0) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    'batchNumber',
                    'This expiry-sensitive item already has on-hand stock without batch records. Record the existing batches first, then receive new stock.',
                );
            }

            $usesBatchTracking = $requiresExpiryTracking || $hasBatchRecords;

            $batchNumber = $this->normalizeBatchNumber($payload['batch_number'] ?? null);
            $lotNumber = $this->stringOrNull($payload['lot_number'] ?? null);
            $binLocation = $this->stringOrNull($payload['bin_location'] ?? null);
            $manufactureDate = $this->normalizeDateOrNull($payload['manufacture_date'] ?? null);
            $expiryDate = $this->normalizeDateOrNull($payload['expiry_date'] ?? null);
            $receivedUnitCost = $payload['received_unit_cost'] ?? null;
            $receivedUnitCost = $receivedUnitCost === null || $receivedUnitCost === ''
                ? null
                : round((float) $receivedUnitCost, 2);

            if ($usesBatchTracking && $batchNumber === null) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    'batchNumber',
                    'Batch number is required for this receipt because the item is batch-tracked.',
                );
            }

            if ($requiresExpiryTracking && $expiryDate === null) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    'expiryDate',
                    'Expiry date is required for expiry-sensitive stock receipts.',
                );
            }

            if ($expiryDate !== null && $manufactureDate !== null && $expiryDate->lt($manufactureDate)) {
                $this->throwValidationException(
                    $validationExceptionClass,
                    'expiryDate',
                    'Expiry date must be on or after the manufacture date.',
                );
            }

            $batch = null;
            if ($usesBatchTracking && $batchNumber !== null) {
                $batchQuery = InventoryBatchModel::query()
                    ->where('item_id', $item->id)
                    ->where('batch_number', $batchNumber);

                if ($warehouseId !== null) {
                    $batchQuery->where('warehouse_id', $warehouseId);
                } else {
                    $batchQuery->whereNull('warehouse_id');
                }

                $batch = $batchQuery
                    ->lockForUpdate()
                    ->first();

                if ($batch instanceof InventoryBatchModel) {
                    $this->assertReceiptBatchCompatibility(
                        batch: $batch,
                        lotNumber: $lotNumber,
                        manufactureDate: $manufactureDate,
                        expiryDate: $expiryDate,
                        validationExceptionClass: $validationExceptionClass,
                    );

                    $batch->forceFill([
                        'lot_number' => $lotNumber ?? $batch->lot_number,
                        'manufacture_date' => $manufactureDate?->toDateString() ?? $batch->manufacture_date,
                        'expiry_date' => $expiryDate?->toDateString() ?? $batch->expiry_date,
                        'quantity' => round((float) ($batch->quantity ?? 0) + $baseQuantity, 3),
                        'warehouse_id' => $warehouseId,
                        'bin_location' => $binLocation ?? $batch->bin_location,
                        'supplier_id' => $this->stringOrNull($payload['source_supplier_id'] ?? null) ?? $batch->supplier_id,
                        // Inventory_MasterData_Alignment_Plan.md Phase 7: manufacturer is a
                        // receipt-time fact, not fixed per item -- an explicit value on this
                        // receipt wins, but a repeat receipt into the same open batch keeps
                        // whatever the batch was already recorded with.
                        'manufacturer' => $this->stringOrNull($payload['manufacturer'] ?? null) ?? $batch->manufacturer,
                        'unit_cost' => $receivedUnitCost ?? $batch->unit_cost,
                        'status' => 'available',
                        'notes' => $payload['notes'] ?? $batch->notes,
                    ])->save();
                } else {
                    $batch = InventoryBatchModel::query()->create([
                        'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                        'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                        'item_id' => $item->id,
                        'internal_batch_number' => $this->generateInternalBatchNumber(),
                        'batch_number' => $batchNumber,
                        'lot_number' => $lotNumber,
                        'manufacture_date' => $manufactureDate?->toDateString(),
                        'expiry_date' => $expiryDate?->toDateString(),
                        'quantity' => $baseQuantity,
                        'warehouse_id' => $warehouseId,
                        'bin_location' => $binLocation,
                        'supplier_id' => $this->stringOrNull($payload['source_supplier_id'] ?? null),
                        // Defaults to the item's manufacturer preference when this
                        // receipt didn't specify one, same as CreateInventoryBatchUseCase.
                        'manufacturer' => $this->stringOrNull($payload['manufacturer'] ?? null) ?? $this->stringOrNull($item->manufacturer ?? null),
                        'unit_cost' => $receivedUnitCost,
                        'status' => 'available',
                        'notes' => $payload['notes'] ?? null,
                    ]);
                }
            }

            $stockBefore = $currentStock;
            $stockAfter = round($stockBefore + $baseQuantity, 3);
            $item->forceFill(['current_stock' => $stockAfter])->save();

            $movementType = InventoryStockMovementType::tryFrom((string) ($payload['movement_type'] ?? ''))
                ?? InventoryStockMovementType::RECEIVE;
            $adjustmentDirection = $this->stringOrNull($payload['adjustment_direction'] ?? null);

            $movement = InventoryStockMovementModel::query()->create([
                'tenant_id' => $this->stringOrNull($payload['tenant_id'] ?? null) ?? $this->platformScopeContext->tenantId(),
                'facility_id' => $this->stringOrNull($payload['facility_id'] ?? null) ?? $this->platformScopeContext->facilityId(),
                'item_id' => $item->id,
                'batch_id' => $batch?->id,
                'internal_batch_number' => $batch instanceof InventoryBatchModel ? $batch->internal_batch_number : null,
                'procurement_request_id' => $payload['procurement_request_id'] ?? null,
                'source_supplier_id' => $payload['source_supplier_id'] ?? null,
                'source_warehouse_id' => $payload['source_warehouse_id'] ?? null,
                'destination_warehouse_id' => $warehouseId,
                'destination_department_id' => $payload['destination_department_id'] ?? null,
                'source_type' => $payload['source_type'] ?? null,
                'source_id' => $payload['source_id'] ?? null,
                'clinical_catalog_item_id' => $payload['clinical_catalog_item_id'] ?? null,
                'consumption_recipe_item_id' => $payload['consumption_recipe_item_id'] ?? null,
                'movement_type' => $movementType->value,
                'adjustment_direction' => $movementType === InventoryStockMovementType::ADJUST
                    ? ($adjustmentDirection ?? 'increase')
                    : $adjustmentDirection,
                'is_opening_stock' => (bool) ($payload['is_opening_stock'] ?? false),
                'quantity' => $baseQuantity,
                'quantity_delta' => $baseQuantity,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reason' => $payload['reason'] ?? null,
                'reason_code' => $payload['reason_code'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'actor_id' => $actorId,
                'metadata' => $this->mergedMetadata(
                    array_merge($payload['metadata'] ?? [], $conversion),
                    $batch instanceof InventoryBatchModel
                        ? [
                            'batchMode' => 'tracked',
                            'trackedReceipt' => true,
                            'batchNumber' => $batch->batch_number,
                            'lotNumber' => $batch->lot_number,
                            'manufactureDate' => $batch->manufacture_date?->toDateString(),
                            'expiryDate' => $batch->expiry_date?->toDateString(),
                            'receivingWarehouseId' => $warehouseId,
                            'binLocation' => $batch->bin_location,
                        ]
                        : [
                            'batchMode' => 'untracked',
                            'trackedReceipt' => false,
                            'receivingWarehouseId' => $warehouseId,
                        ],
                ),
                'requested_quantity' => $conversion['requested_quantity'],
                'requested_unit' => $conversion['requested_unit'],
                'requested_unit_id' => $conversion['requested_unit_id'],
                'base_unit' => $conversion['base_unit'],
                'base_quantity' => $conversion['base_quantity'],
                'conversion_factor' => $conversion['conversion_factor'],
                'occurred_at' => $occurredAt,
                'created_at' => now(),
            ]);

            $movementArray = $movement->toArray();
            $movementArray['item'] = $item->fresh()?->toArray();

            if ($batch instanceof InventoryBatchModel) {
                $movementArray['batch'] = $batch->fresh()?->toArray();
            }

            return $movementArray;
        });
    }

    /**
     * @param  class-string<\RuntimeException>  $validationExceptionClass
     */
    private function throwValidationException(string $validationExceptionClass, string $field, string $message): never
    {
        throw new $validationExceptionClass($field, $message);
    }
}
