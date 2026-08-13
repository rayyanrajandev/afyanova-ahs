<?php

namespace App\Modules\InventoryProcurement\Domain\Services;

use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Support\CatalogGovernance\StandardsCodeSupport;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 9. "What does an inventory item
 * look like when it's linked to this catalog item" was independently
 * reimplemented, in near-identical shape, in four places:
 * CreateInventoryItemUseCase, UpdateInventoryItemUseCase,
 * BulkCreateInventoryItemsFromCatalogUseCase, and
 * CatalogDownstreamSyncService::syncToInventory() -- the last of which is a
 * genuinely separate item-creation path (Phase 6/7 found it independently
 * calling InventoryItemModel::create(), bypassing CreateInventoryItemUseCase
 * entirely). A rule change to how identity is derived from the catalog
 * previously meant finding and editing all four call sites by hand; now it's
 * one.
 */
class CatalogIdentityResolver
{
    public function __construct(
        private readonly StandardsCodeSupport $standardsCodeSupport,
    ) {}

    /**
     * @return array{item_name: string, unit: string, dispensing_unit: string|null, conversion_factor: float|null, subcategory: string|null, codes: array<string, mixed>|null}
     */
    public function resolve(ClinicalCatalogItemModel $catalogItem): array
    {
        $metadata = is_array($catalogItem->metadata) ? $catalogItem->metadata : [];
        $codes = is_array($catalogItem->codes) ? $catalogItem->codes : [];
        $conversionFactor = $metadata['conversionFactor'] ?? $metadata['conversion_factor'] ?? null;

        return [
            'item_name' => trim((string) $catalogItem->name),
            'unit' => $metadata['stockUnit'] ?? $metadata['stock_unit'] ?? $catalogItem->unit ?? 'Each',
            'dispensing_unit' => $metadata['dispensingUnit'] ?? $metadata['dispensing_unit'] ?? $catalogItem->unit ?? null,
            'conversion_factor' => is_numeric($conversionFactor) ? (float) $conversionFactor : null,
            'subcategory' => $catalogItem->category ?? null,
            'codes' => $this->standardsCodeSupport->normalize($codes),
        ];
    }
}
