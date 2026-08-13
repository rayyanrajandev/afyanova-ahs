<?php

namespace App\Modules\InventoryProcurement\Domain\Services;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;

readonly class InventoryUnitConversionService
{
    public function resolveUnit(string $itemId, ?string $unitId, ?string $unitName): InventoryItemUnitModel
    {
        if ($unitId !== null) {
            $unit = InventoryItemUnitModel::query()->where('item_id', $itemId)->whereKey($unitId)->first();
            if ($unit instanceof InventoryItemUnitModel) {
                return $unit;
            }
        }

        if ($unitName !== null) {
            $normalizedName = strtolower(trim((string) $unitName));
            $unit = InventoryItemUnitModel::query()
                ->where('item_id', $itemId)
                ->whereRaw('LOWER(unit_name) = ?', [$normalizedName])
                ->first();

            if ($unit instanceof InventoryItemUnitModel) {
                return $unit;
            }
        }

        // Fall back to the base unit when no unit is specified
        $baseUnit = InventoryItemUnitModel::query()
            ->where('item_id', $itemId)
            ->where('is_base_unit', true)
            ->where('is_active', true)
            ->first();

        if ($baseUnit instanceof InventoryItemUnitModel) {
            return $baseUnit;
        }

        throw new InventoryStockOperationValidationException(
            'unit',
            'Selected inventory unit could not be resolved for this item.',
        );
    }

    /**
     * @return array{
     *     requested_quantity: float,
     *     requested_unit: string,
     *     requested_unit_id: string,
     *     base_unit: string,
     *     base_quantity: float,
     *     conversion_factor: float,
     * }
     */
    public function toBaseQuantity(string $itemId, float $quantity, ?string $unitId, ?string $unitName): array
    {
        $unit = $this->resolveUnit($itemId, $unitId, $unitName);

        if (! $unit->is_active) {
            throw new InventoryStockOperationValidationException(
                'unit',
                'Selected inventory unit is not active.',
            );
        }

        $baseUnit = $this->resolveBaseUnit($itemId);
        $baseQuantity = round($quantity * (float) ($unit->base_quantity ?? 1), 6);
        $conversionFactor = (float) ($unit->base_quantity ?? 1);

        return [
            'requested_quantity' => round($quantity, 6),
            'requested_unit' => (string) ($unit->unit_name ?? $unitName),
            'requested_unit_id' => (string) $unit->id,
            'base_unit' => (string) ($baseUnit->unit_name ?? $baseUnit->unit_code ?? 'base'),
            'base_quantity' => $baseQuantity,
            'conversion_factor' => $conversionFactor,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSellableUnits(string $itemId): array
    {
        $units = InventoryItemUnitModel::query()
            ->where('item_id', $itemId)
            ->where('is_active', true)
            ->orderByDesc('is_base_unit')
            ->orderBy('unit_name')
            ->get();

        return $units->map(fn (InventoryItemUnitModel $unit): array => [
            'id' => (string) $unit->id,
            'unitName' => (string) $unit->unit_name,
            'unitCode' => $unit->unit_code !== null ? (string) $unit->unit_code : null,
            'baseQuantity' => (float) $unit->base_quantity,
            'isBaseUnit' => (bool) $unit->is_base_unit,
            'isDefaultSalesUnit' => (bool) $unit->is_default_sales_unit,
            'isDefaultPurchaseUnit' => (bool) $unit->is_default_purchase_unit,
            'barcode' => $unit->barcode !== null ? (string) $unit->barcode : null,
            'metadata' => $unit->metadata ?? [],
        ])->all();
    }

    public function assertBaseUnitImmutable(InventoryItemModel $item, ?string $requestedUnit): void
    {
        if ($requestedUnit === null) {
            return;
        }

        $normalized = strtolower(trim((string) $requestedUnit));
        $currentBase = strtolower(trim((string) ($item->unit ?? '')));

        if ($normalized === '' || $currentBase === '') {
            return;
        }

        if ($normalized !== $currentBase) {
            throw new InventoryStockOperationValidationException(
                'unit',
                'Base stock unit cannot be changed after stock movements exist.',
            );
        }
    }

    private function resolveBaseUnit(string $itemId): InventoryItemUnitModel
    {
        $baseUnit = InventoryItemUnitModel::query()
            ->where('item_id', $itemId)
            ->where('is_base_unit', true)
            ->where('is_active', true)
            ->first();

        if ($baseUnit instanceof InventoryItemUnitModel) {
            return $baseUnit;
        }

        $unit = InventoryItemUnitModel::query()->where('item_id', $itemId)->first();
        if ($unit instanceof InventoryItemUnitModel) {
            return $unit;
        }

        throw new InventoryItemNotFoundException('Active base inventory unit could not be resolved for this item.');
    }
}