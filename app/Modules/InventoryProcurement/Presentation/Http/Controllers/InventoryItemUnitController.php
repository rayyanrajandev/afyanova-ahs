<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Application\Exceptions\InventoryStockOperationValidationException;
use App\Modules\InventoryProcurement\Domain\Services\InventoryItemUnitPricingService;
use App\Modules\InventoryProcurement\Domain\Services\InventoryUnitConversionService;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitPriceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryItemUnitController
{
    public function __construct(
        private readonly InventoryUnitConversionService $unitConversionService,
        private readonly InventoryItemUnitPricingService $unitPricingService,
    ) {}

    /**
     * @return JsonResponse
     */
    public function index(Request $request, string $itemId): JsonResponse
    {
        $item = InventoryItemModel::query()->find($itemId);
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $units = $this->unitConversionService->listSellableUnits($itemId);

        return response()->json([
            'data' => $units,
            'item' => [
                'id' => (string) $item->id,
                'itemName' => (string) ($item->item_name ?? ''),
                'baseUnit' => (string) ($item->unit ?? ''),
                'currentStock' => (float) ($item->current_stock ?? 0),
            ],
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function store(Request $request, string $itemId): JsonResponse
    {
        $item = InventoryItemModel::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $validated = $request->validate([
            'unit_name' => ['required', 'string', 'max:50'],
            'unit_code' => ['nullable', 'string', 'max:50'],
            'base_quantity' => ['required', 'numeric', 'min:0.000001'],
            'is_default_sales_unit' => ['boolean'],
            'is_default_purchase_unit' => ['boolean'],
            'barcode' => ['nullable', 'string', 'max:100'],
        ]);

        $normalizedName = strtolower(trim((string) $validated['unit_name']));
        $isBaseUnit = $normalizedName === strtolower(trim((string) ($item->unit ?? '')));

        if ($isBaseUnit && (float) $validated['base_quantity'] !== 1.0) {
            throw ValidationException::withMessages([
                'base_quantity' => ['Base unit must have a base quantity of 1.'],
            ]);
        }

        $existingBase = InventoryItemUnitModel::query()
            ->where('item_id', $item->id)
            ->where('is_base_unit', true)
            ->where('is_active', true)
            ->exists();

        if ($isBaseUnit && $existingBase) {
            throw ValidationException::withMessages([
                'unit_name' => ['This item already has an active base unit.'],
            ]);
        }

        if ($validated['is_default_sales_unit'] ?? false) {
            InventoryItemUnitModel::query()
                ->where('item_id', $item->id)
                ->where('is_active', true)
                ->update(['is_default_sales_unit' => false]);
        }

        if ($validated['is_default_purchase_unit'] ?? false) {
            InventoryItemUnitModel::query()
                ->where('item_id', $item->id)
                ->where('is_active', true)
                ->update(['is_default_purchase_unit' => false]);
        }

        $unit = InventoryItemUnitModel::query()->create([
            'tenant_id' => (string) $item->tenant_id,
            'facility_id' => $item->facility_id !== null ? (string) $item->facility_id : null,
            'item_id' => $item->id,
            'unit_name' => $normalizedName,
            'unit_code' => $validated['unit_code'] ?? null,
            'base_quantity' => (float) $validated['base_quantity'],
            'is_base_unit' => $isBaseUnit,
            'is_default_sales_unit' => (bool) ($validated['is_default_sales_unit'] ?? false),
            'is_default_purchase_unit' => (bool) ($validated['is_default_purchase_unit'] ?? false),
            'is_active' => true,
            'barcode' => $validated['barcode'] ?? null,
        ]);

        return response()->json([
            'data' => $this->unitConversionService->listSellableUnits($itemId),
        ], 201);
    }

    public function update(Request $request, string $itemId, string $unitId): JsonResponse
    {
        $item = InventoryItemModel::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $unit = InventoryItemUnitModel::query()
            ->where('item_id', $item->id)
            ->whereKey($unitId)
            ->first();

        if (! $unit instanceof InventoryItemUnitModel) {
            throw new InventoryItemNotFoundException('Inventory item unit not found.');
        }

        if ($unit->is_base_unit) {
            throw new InventoryStockOperationValidationException(
                'unit',
                'Base unit cannot be updated here.',
            );
        }

        $validated = $request->validate([
            'unit_name' => ['sometimes', 'string', 'max:50'],
            'unit_code' => ['nullable', 'string', 'max:50'],
            'base_quantity' => ['sometimes', 'numeric', 'min:0.000001'],
            'is_default_sales_unit' => ['boolean'],
            'is_default_purchase_unit' => ['boolean'],
            'is_active' => ['boolean'],
            'barcode' => ['nullable', 'string', 'max:100'],
        ]);

        if (! empty($validated['is_default_sales_unit'])) {
            InventoryItemUnitModel::query()
                ->where('item_id', $item->id)
                ->where('id', '!=', $unit->id)
                ->where('is_active', true)
                ->update(['is_default_sales_unit' => false]);
        }

        if (! empty($validated['is_default_purchase_unit'])) {
            InventoryItemUnitModel::query()
                ->where('item_id', $item->id)
                ->where('id', '!=', $unit->id)
                ->where('is_active', true)
                ->update(['is_default_purchase_unit' => false]);
        }

        $unit->forceFill([
            'unit_name' => isset($validated['unit_name']) ? strtolower(trim((string) $validated['unit_name'])) : $unit->unit_name,
            'unit_code' => $validated['unit_code'] ?? $unit->unit_code,
            'base_quantity' => isset($validated['base_quantity']) ? (float) $validated['base_quantity'] : $unit->base_quantity,
            'is_default_sales_unit' => (bool) ($validated['is_default_sales_unit'] ?? $unit->is_default_sales_unit),
            'is_default_purchase_unit' => (bool) ($validated['is_default_purchase_unit'] ?? $unit->is_default_purchase_unit),
            'is_active' => (bool) ($validated['is_active'] ?? $unit->is_active),
            'barcode' => $validated['barcode'] ?? $unit->barcode,
        ])->save();

        return response()->json([
            'data' => $this->unitConversionService->listSellableUnits($itemId),
        ]);
    }

    public function destroy(string $itemId, string $unitId): JsonResponse
    {
        $item = InventoryItemModel::query()->whereKey($itemId)->lockForUpdate()->first();
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $unit = InventoryItemUnitModel::query()
            ->where('item_id', $item->id)
            ->whereKey($unitId)
            ->first();

        if (! $unit instanceof InventoryItemUnitModel) {
            throw new InventoryItemNotFoundException('Inventory item unit not found.');
        }

        if ($unit->is_base_unit) {
            throw new InventoryStockOperationValidationException(
                'unit',
                'Base unit cannot be deleted.',
            );
        }

        $hasHistory = DB::transaction(function () use ($unitId): bool {
            $hasPrice = InventoryItemUnitPriceModel::query()->where('inventory_item_unit_id', $unitId)->exists();
            if ($hasPrice) {
                return true;
            }

            $movementCount = DB::table('inventory_stock_movements')
                ->where('requested_unit_id', $unitId)
                ->exists();

            return $movementCount;
        });

        if ($hasHistory) {
            $unit->forceFill(['is_active' => false])->save();

            return response()->json([
                'message' => 'Unit has historical usage and was deactivated instead of deleted.',
            ]);
        }

        $unit->delete();

        return response()->json([
            'data' => $this->unitConversionService->listSellableUnits($itemId),
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function prices(Request $request, string $itemId): JsonResponse
    {
        $item = InventoryItemModel::query()->find($itemId);
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $prices = $this->unitPricingService->listPricesForItem($itemId);

        return response()->json([
            'data' => $prices,
            'item' => [
                'id' => (string) $item->id,
                'itemName' => (string) ($item->item_name ?? ''),
            ],
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function storePrice(Request $request, string $itemId): JsonResponse
    {
        $item = InventoryItemModel::query()->find($itemId);
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $validated = $request->validate([
            'inventory_item_unit_id' => ['required', 'uuid', 'exists:inventory_item_units,id'],
            'price_type' => ['required', 'string', 'max:40', Rule::in(['purchase', 'retail', 'wholesale', 'insurance', 'contract'])],
            'price' => ['required', 'numeric', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'billing_payer_contract_id' => ['nullable', 'uuid', 'exists:billing_payer_contracts,id'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $validated['item_id'] = $item->id;
        $validated['tenant_id'] = (string) $item->tenant_id;
        $validated['facility_id'] = $item->facility_id !== null ? (string) $item->facility_id : null;
        $validated['currency_code'] = strtoupper((string) ($validated['currency_code'] ?? 'TZS'));
        $validated['effective_from'] = isset($validated['effective_from']) ? Carbon::parse($validated['effective_from']) : null;
        $validated['effective_to'] = isset($validated['effective_to']) ? Carbon::parse($validated['effective_to']) : null;

        if ($validated['price_type'] === 'insurance' || $validated['price_type'] === 'contract') {
            if (empty($validated['billing_payer_contract_id'])) {
                throw ValidationException::withMessages([
                    'billing_payer_contract_id' => ['Payer contract is required for insurance and contract prices.'],
                ]);
            }
        } else {
            $validated['billing_payer_contract_id'] = null;
        }

        DB::table('inventory_item_unit_prices')
            ->where('item_id', $item->id)
            ->where('inventory_item_unit_id', $validated['inventory_item_unit_id'])
            ->where('price_type', $validated['price_type'])
            ->where('is_active', true)
            ->where(function ($query) use ($validated): void {
                $query->where(function ($q) use ($validated): void {
                    $q->whereNull('effective_from')
                        ->where(($validated['effective_from'] ?? null) === null || 'effective_from', '<', $validated['effective_from']);
                })->orWhere(function ($q) use ($validated): void {
                    $q->whereNotNull('effective_from')
                        ->where('effective_from', '<', $validated['effective_from']);
                });
            })
            ->update(['is_active' => false]);

        $price = InventoryItemUnitPriceModel::query()->create([
            'tenant_id' => $validated['tenant_id'],
            'facility_id' => $validated['facility_id'],
            'item_id' => $item->id,
            'inventory_item_unit_id' => $validated['inventory_item_unit_id'],
            'price_type' => $validated['price_type'],
            'billing_payer_contract_id' => $validated['billing_payer_contract_id'],
            'price' => (float) $validated['price'],
            'currency_code' => $validated['currency_code'],
            'effective_from' => $validated['effective_from'],
            'effective_to' => $validated['effective_to'],
            'is_active' => true,
        ]);

        return response()->json([
            'data' => $this->unitPricingService->listPricesForItem($itemId),
        ], 201);
    }

    public function destroyPrice(string $itemId, string $priceId): JsonResponse
    {
        $item = InventoryItemModel::query()->find($itemId);
        if (! $item instanceof InventoryItemModel) {
            throw new InventoryItemNotFoundException('Inventory item not found.');
        }

        $price = InventoryItemUnitPriceModel::query()
            ->where('item_id', $item->id)
            ->whereKey($priceId)
            ->first();

        if (! $price instanceof InventoryItemUnitPriceModel) {
            throw new InventoryItemNotFoundException('Inventory item unit price not found.');
        }

        $price->forceFill(['is_active' => false])->save();

        return response()->json([
            'data' => $this->unitPricingService->listPricesForItem($itemId),
        ]);
    }
}