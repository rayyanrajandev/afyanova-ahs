<?php

namespace App\Modules\InventoryProcurement\Domain\Services;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitPriceModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

readonly class InventoryItemUnitPricingService
{
    /**
     * @return array{
     *     price: float,
     *     currencyCode: string,
     *     priceType: string,
     *     payerContractId: string|null,
     *     effectiveFrom: string|null,
     *     effectiveTo: string|null,
     *     unitId: string,
     *     unitName: string,
     *     baseQuantity: float,
     * }
     */
    public function resolvePrice(
        string $itemId,
        string $unitId,
        string $priceType,
        string $currencyCode,
        ?string $payerContractId = null,
        mixed $effectiveAt = null,
    ): array {
        $asOf = $this->normalizeEffectiveAt($effectiveAt);

        $query = InventoryItemUnitPriceModel::query()
            ->where('item_id', $itemId)
            ->where('inventory_item_unit_id', $unitId)
            ->where('price_type', $priceType)
            ->where('is_active', true)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $asOf);
            });

        if ($payerContractId !== null) {
            $query->where('billing_payer_contract_id', $payerContractId);
        } else {
            $query->whereNull('billing_payer_contract_id');
        }

        $priceRow = $query->orderByDesc('effective_from')->orderByDesc('created_at')->first();

        if (! $priceRow instanceof InventoryItemUnitPriceModel) {
            throw new \RuntimeException('No active unit price found for the requested item, unit, and price type.');
        }

        $unit = InventoryItemUnitModel::query()->whereKey($unitId)->first();
        if (! $unit instanceof InventoryItemUnitModel) {
            throw new \RuntimeException('Inventory item unit not found.');
        }

        return [
            'price' => (float) ($priceRow->price ?? 0),
            'currencyCode' => (string) ($priceRow->currency_code ?? $currencyCode),
            'priceType' => (string) $priceRow->price_type,
            'payerContractId' => $priceRow->billing_payer_contract_id !== null ? (string) $priceRow->billing_payer_contract_id : null,
            'effectiveFrom' => $priceRow->effective_from !== null ? $priceRow->effective_from->toDateTimeString() : null,
            'effectiveTo' => $priceRow->effective_to !== null ? $priceRow->effective_to->toDateTimeString() : null,
            'unitId' => (string) $unit->id,
            'unitName' => (string) ($unit->unit_name ?? ''),
            'baseQuantity' => (float) ($unit->base_quantity ?? 1),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPricesForItem(string $itemId): array
    {
        $rows = InventoryItemUnitPriceModel::query()
            ->where('item_id', $itemId)
            ->where('is_active', true)
            ->orderBy('inventory_item_unit_id')
            ->orderBy('price_type')
            ->orderByDesc('effective_from')
            ->get();

        return $rows->map(fn (InventoryItemUnitPriceModel $row): array => [
            'id' => (string) $row->id,
            'unitId' => (string) $row->inventory_item_unit_id,
            'priceType' => (string) $row->price_type,
            'price' => (float) $row->price,
            'currencyCode' => (string) ($row->currency_code ?? 'TZS'),
            'payerContractId' => $row->billing_payer_contract_id !== null ? (string) $row->billing_payer_contract_id : null,
            'effectiveFrom' => $row->effective_from !== null ? $row->effective_from->toDateTimeString() : null,
            'effectiveTo' => $row->effective_to !== null ? $row->effective_to->toDateTimeString() : null,
            'createdAt' => $row->created_at !== null ? $row->created_at->toDateTimeString() : null,
            'updatedAt' => $row->updated_at !== null ? $row->updated_at->toDateTimeString() : null,
        ])->all();
    }

    private function normalizeEffectiveAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value === null) {
            return now();
        }

        return Carbon::parse((string) $value);
    }
}