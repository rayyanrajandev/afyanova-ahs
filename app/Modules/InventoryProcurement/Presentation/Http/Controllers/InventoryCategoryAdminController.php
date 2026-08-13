<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryCategoryModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySubcategoryModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 5. Admin management for
 * inventory_categories/inventory_subcategories.
 *
 * Deliberately asymmetric: subcategories are freely creatable/editable here
 * (they're pure reference data, nothing else in the app depends on a fixed
 * list of subcategory codes). Categories are NOT creatable here -- the
 * InventoryItemCategory PHP enum is still the source of truth for category
 * *behavior* (InventoryClinicalLinkGuard, form validation, dynamic-rendering
 * flags all key off the enum, not this table -- see the audit's Phase 5
 * notes). A category row with no matching enum case would look real in this
 * admin screen but silently fail everywhere else in the app. Only the
 * presentation fields (label, description, active/sort order) are editable
 * until the enum itself is retired in a later phase.
 */
class InventoryCategoryAdminController
{
    public function index(): JsonResponse
    {
        $categories = InventoryCategoryModel::query()
            ->with(['subcategories' => fn ($query) => $query->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => $categories->map(fn (InventoryCategoryModel $category) => $this->toCategoryPayload($category))->values(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = InventoryCategoryModel::query()->find($id);
        abort_if($category === null, 404, 'Inventory category not found.');

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);

        $category->forceFill([
            'label' => $validated['label'] ?? $category->label,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $category->description,
            'is_active' => $validated['isActive'] ?? $category->is_active,
            'sort_order' => $validated['sortOrder'] ?? $category->sort_order,
        ])->save();

        return response()->json([
            'data' => $this->toCategoryPayload($category->fresh()->load('subcategories')),
        ]);
    }

    public function storeSubcategory(Request $request, string $categoryId): JsonResponse
    {
        $category = InventoryCategoryModel::query()->find($categoryId);
        abort_if($category === null, 404, 'Inventory category not found.');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:80', 'alpha_dash'],
            'label' => ['required', 'string', 'max:120'],
            'sortOrder' => ['nullable', 'integer', 'min:0'],
        ]);

        $exists = InventorySubcategoryModel::query()
            ->where('category_id', $category->id)
            ->whereRaw('LOWER(code) = ?', [strtolower($validated['code'])])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => 'A subcategory with this code already exists for this category.',
                'code' => 'VALIDATION_ERROR',
                'errors' => ['code' => ['A subcategory with this code already exists for this category.']],
            ], 422);
        }

        $subcategory = $category->subcategories()->create([
            'code' => strtolower(trim($validated['code'])),
            'label' => trim($validated['label']),
            'is_active' => true,
            'sort_order' => $validated['sortOrder'] ?? ($category->subcategories()->max('sort_order') + 1),
        ]);

        return response()->json([
            'data' => $this->toSubcategoryPayload($subcategory),
        ], 201);
    }

    public function updateSubcategory(Request $request, string $categoryId, string $id): JsonResponse
    {
        $subcategory = InventorySubcategoryModel::query()
            ->where('category_id', $categoryId)
            ->find($id);
        abort_if($subcategory === null, 404, 'Inventory subcategory not found.');

        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
        ]);

        $subcategory->forceFill([
            'label' => $validated['label'] ?? $subcategory->label,
            'is_active' => $validated['isActive'] ?? $subcategory->is_active,
            'sort_order' => $validated['sortOrder'] ?? $subcategory->sort_order,
        ])->save();

        return response()->json([
            'data' => $this->toSubcategoryPayload($subcategory->fresh()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toCategoryPayload(InventoryCategoryModel $category): array
    {
        return [
            'id' => $category->id,
            'code' => $category->code,
            'label' => $category->label,
            'description' => $category->description,
            'formTemplate' => $category->form_template,
            'requiresExpiryTracking' => $category->requires_expiry_tracking,
            'requiresColdChain' => $category->requires_cold_chain,
            'controlledSubstanceEligible' => $category->controlled_substance_eligible,
            'supportsMedicineDetails' => $category->supports_medicine_details,
            'supportsStorageFields' => $category->supports_storage_fields,
            'supportsClinicalClassification' => $category->supports_clinical_classification,
            'isActive' => $category->is_active,
            'sortOrder' => $category->sort_order,
            'subcategories' => $category->subcategories->map(fn (InventorySubcategoryModel $subcategory) => $this->toSubcategoryPayload($subcategory))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toSubcategoryPayload(InventorySubcategoryModel $subcategory): array
    {
        return [
            'id' => $subcategory->id,
            'categoryId' => $subcategory->category_id,
            'code' => $subcategory->code,
            'label' => $subcategory->label,
            'isActive' => $subcategory->is_active,
            'sortOrder' => $subcategory->sort_order,
        ];
    }
}
