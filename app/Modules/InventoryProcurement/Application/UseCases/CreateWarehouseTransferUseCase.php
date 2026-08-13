<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseTransferRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferLineModel;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Support\Carbon;

class CreateWarehouseTransferUseCase
{
    public function __construct(
        private readonly InventoryWarehouseTransferRepositoryInterface $transferRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, string $userId): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $sourceWarehouseId = (string) ($payload['source_warehouse_id'] ?? '');
        $destinationWarehouseId = (string) ($payload['destination_warehouse_id'] ?? '');
        [$sourceWarehouse, $destinationWarehouse, $tenantId, $facilityId] = $this->resolveTransferScope(
            $sourceWarehouseId,
            $destinationWarehouseId,
        );

        $this->validateTransferLines($payload['lines'] ?? [], $sourceWarehouseId);

        $transferNumber = 'TRF-' . now()->format('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));

        $transfer = $this->transferRepository->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'transfer_number' => $transferNumber,
            'source_warehouse_id' => $sourceWarehouse->id,
            'destination_warehouse_id' => $destinationWarehouse->id,
            'status' => 'draft',
            'priority' => $payload['priority'] ?? 'normal',
            'reason' => $payload['reason'] ?? null,
            'requested_by_user_id' => $userId,
            'notes' => $payload['notes'] ?? null,
        ]);

        foreach ($payload['lines'] as $line) {
            $item = InventoryItemModel::query()->find($line['item_id']);

            InventoryWarehouseTransferLineModel::query()->create([
                'transfer_id' => $transfer['id'],
                'item_id' => $line['item_id'],
                'batch_id' => $line['batch_id'] ?? null,
                'requested_quantity' => $line['requested_quantity'],
                'unit' => $line['unit'] ?? ($item?->unit ?? null),
                'notes' => $line['notes'] ?? null,
            ]);
        }

        return $this->transferRepository->findById($transfer['id']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function validateTransferLines(array $lines, string $sourceWarehouseId): void
    {
        foreach ($lines as $index => $line) {
            $itemId = trim((string) ($line['item_id'] ?? ''));
            $requestedQuantity = round((float) ($line['requested_quantity'] ?? 0), 3);
            $batchId = trim((string) ($line['batch_id'] ?? ''));

            $item = InventoryItemModel::query()->find($itemId);
            if (! $item instanceof InventoryItemModel) {
                throw new InventoryStockOperationValidationException(
                    "lines.$index.itemId",
                    'Inventory item was not found for this transfer line.',
                );
            }

            if ($this->usesBatchTracking($item)) {
                if ($batchId === '') {
                    throw new InventoryStockOperationValidationException(
                        "lines.$index.batchId",
                        'Tracked inventory transfers require the exact source batch.',
                    );
                }

                $batch = InventoryBatchModel::query()
                    ->whereKey($batchId)
                    ->where('item_id', $item->id)
                    ->where('warehouse_id', $sourceWarehouseId)
                    ->first();

                if (! $batch instanceof InventoryBatchModel) {
                    throw new InventoryStockOperationValidationException(
                        "lines.$index.batchId",
                        'Selected batch was not found in the chosen source warehouse.',
                    );
                }

                if ((string) ($batch->status ?? '') !== 'available') {
                    throw new InventoryStockOperationValidationException(
                        "lines.$index.batchId",
                        'Selected batch is not available for warehouse transfer.',
                    );
                }

                if ($batch->expiry_date instanceof Carbon && $batch->expiry_date->endOfDay()->lt(now())) {
                    throw new InventoryStockOperationValidationException(
                        "lines.$index.batchId",
                        'Expired batches cannot be transferred between warehouses.',
                    );
                }

                if ($requestedQuantity > round((float) ($batch->quantity ?? 0), 3)) {
                    throw new InventoryStockOperationValidationException(
                        "lines.$index.requestedQuantity",
                        'Requested quantity exceeds the available quantity in the selected batch.',
                    );
                }
            }
        }
    }

    /**
     * @return array{InventoryWarehouseModel, InventoryWarehouseModel, ?string, ?string}
     */
    private function resolveTransferScope(string $sourceWarehouseId, string $destinationWarehouseId): array
    {
        $sourceWarehouse = InventoryWarehouseModel::query()->find($sourceWarehouseId);
        if (! $sourceWarehouse instanceof InventoryWarehouseModel) {
            throw new InventoryStockOperationValidationException(
                'sourceWarehouseId',
                'Source warehouse was not found.',
            );
        }

        $destinationWarehouse = InventoryWarehouseModel::query()->find($destinationWarehouseId);
        if (! $destinationWarehouse instanceof InventoryWarehouseModel) {
            throw new InventoryStockOperationValidationException(
                'destinationWarehouseId',
                'Destination warehouse was not found.',
            );
        }

        $tenantId = $this->platformScopeContext->tenantId() ?? $this->nullableString($sourceWarehouse->tenant_id);
        $facilityId = $this->platformScopeContext->facilityId() ?? $this->nullableString($sourceWarehouse->facility_id);

        $sourceTenantId = $this->nullableString($sourceWarehouse->tenant_id);
        $destinationTenantId = $this->nullableString($destinationWarehouse->tenant_id);
        if ($sourceTenantId !== null && $destinationTenantId !== null && $sourceTenantId !== $destinationTenantId) {
            throw new InventoryStockOperationValidationException(
                'destinationWarehouseId',
                'Warehouse transfers cannot cross tenant boundaries.',
            );
        }

        $sourceFacilityId = $this->nullableString($sourceWarehouse->facility_id);
        $destinationFacilityId = $this->nullableString($destinationWarehouse->facility_id);
        if ($sourceFacilityId !== null && $destinationFacilityId !== null && $sourceFacilityId !== $destinationFacilityId) {
            throw new InventoryStockOperationValidationException(
                'destinationWarehouseId',
                'Warehouse transfers must stay within the same facility.',
            );
        }

        if ($tenantId !== null && $sourceTenantId !== null && $sourceTenantId !== $tenantId) {
            throw new InventoryStockOperationValidationException(
                'sourceWarehouseId',
                'Source warehouse is outside the active tenant scope.',
            );
        }

        if ($tenantId !== null && $destinationTenantId !== null && $destinationTenantId !== $tenantId) {
            throw new InventoryStockOperationValidationException(
                'destinationWarehouseId',
                'Destination warehouse is outside the active tenant scope.',
            );
        }

        if ($facilityId !== null && $sourceFacilityId !== null && $sourceFacilityId !== $facilityId) {
            throw new InventoryStockOperationValidationException(
                'sourceWarehouseId',
                'Source warehouse is outside the active facility scope.',
            );
        }

        if ($facilityId !== null && $destinationFacilityId !== null && $destinationFacilityId !== $facilityId) {
            throw new InventoryStockOperationValidationException(
                'destinationWarehouseId',
                'Destination warehouse is outside the active facility scope.',
            );
        }

        $resolvedTenantId = $tenantId ?? $sourceTenantId ?? $destinationTenantId;
        $resolvedFacilityId = $facilityId ?? $sourceFacilityId ?? $destinationFacilityId;

        if ($resolvedTenantId === null || $resolvedFacilityId === null) {
            throw new InventoryStockOperationValidationException(
                'sourceWarehouseId',
                'Warehouse transfers require an active tenant and facility scope.',
            );
        }

        return [
            $sourceWarehouse,
            $destinationWarehouse,
            $resolvedTenantId,
            $resolvedFacilityId,
        ];
    }

    private function usesBatchTracking(InventoryItemModel $item): bool
    {
        $category = InventoryItemCategory::tryFrom((string) ($item->category ?? ''));
        if ($category?->requiresExpiryTracking() ?? false) {
            return true;
        }

        return InventoryBatchModel::query()
            ->where('item_id', $item->id)
            ->exists();
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
