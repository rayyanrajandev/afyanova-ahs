<?php

namespace App\Modules\Platform\Presentation\Http\Controllers;

use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemPackagingTemplateModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 4. Manages the reusable
 * packaging default for a formulary item (e.g. "blister of 10, box of 10
 * blisters") that CreateInventoryItemUseCase copies into inventory_item_units
 * as a one-time seed when a facility links an inventory item to this catalog
 * item. This is the template, not any one facility's actual packaging.
 */
class ClinicalCatalogPackagingTemplateController
{
    public function index(string $catalogItemId): JsonResponse
    {
        $catalogItem = $this->findFormularyItemOrFail($catalogItemId);

        return response()->json([
            'data' => $catalogItem->packagingTemplates()->orderByDesc('is_base_unit')->get(),
        ]);
    }

    public function store(Request $request, string $catalogItemId): JsonResponse
    {
        $catalogItem = $this->findFormularyItemOrFail($catalogItemId);

        $validated = $request->validate([
            'unit_name' => ['required', 'string', 'max:50'],
            'unit_code' => ['nullable', 'string', 'max:50'],
            'base_quantity' => ['required', 'numeric', 'min:0.000001'],
            'is_base_unit' => ['boolean'],
            'is_default_purchase_unit' => ['boolean'],
            'is_default_sales_unit' => ['boolean'],
        ]);

        $unitName = strtolower(trim((string) $validated['unit_name']));

        if (($validated['is_base_unit'] ?? false) && (float) $validated['base_quantity'] !== 1.0) {
            throw ValidationException::withMessages([
                'base_quantity' => ['Base unit must have a base quantity of 1.'],
            ]);
        }

        if ($validated['is_base_unit'] ?? false) {
            $catalogItem->packagingTemplates()->where('is_base_unit', true)->update(['is_base_unit' => false]);
        }

        if ($validated['is_default_purchase_unit'] ?? false) {
            $catalogItem->packagingTemplates()->update(['is_default_purchase_unit' => false]);
        }

        if ($validated['is_default_sales_unit'] ?? false) {
            $catalogItem->packagingTemplates()->update(['is_default_sales_unit' => false]);
        }

        $catalogItem->packagingTemplates()->create([
            'unit_name' => $unitName,
            'unit_code' => $validated['unit_code'] ?? null,
            'base_quantity' => (float) $validated['base_quantity'],
            'is_base_unit' => (bool) ($validated['is_base_unit'] ?? false),
            'is_default_purchase_unit' => (bool) ($validated['is_default_purchase_unit'] ?? false),
            'is_default_sales_unit' => (bool) ($validated['is_default_sales_unit'] ?? false),
        ]);

        return response()->json([
            'data' => $catalogItem->packagingTemplates()->orderByDesc('is_base_unit')->get(),
        ], 201);
    }

    public function destroy(string $catalogItemId, string $templateId): JsonResponse
    {
        $catalogItem = $this->findFormularyItemOrFail($catalogItemId);

        $template = $catalogItem->packagingTemplates()->whereKey($templateId)->first();
        abort_if($template === null, 404, 'Packaging template not found.');

        $template->delete();

        return response()->json([
            'data' => $catalogItem->packagingTemplates()->orderByDesc('is_base_unit')->get(),
        ]);
    }

    private function findFormularyItemOrFail(string $catalogItemId): ClinicalCatalogItemModel
    {
        $catalogItem = ClinicalCatalogItemModel::query()
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->find($catalogItemId);

        abort_if($catalogItem === null, 404, 'Clinical catalog formulary item not found.');

        return $catalogItem;
    }
}
