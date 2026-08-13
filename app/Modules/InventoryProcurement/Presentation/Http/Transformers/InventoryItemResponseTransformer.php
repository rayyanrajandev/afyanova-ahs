<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

use App\Support\CatalogGovernance\StandardsCodeSupport;

class InventoryItemResponseTransformer
{
    public static function transform(array $item): array
    {
        // When linked to a clinical catalog, read identity fields from the catalog item
        // to ensure the response always reflects the latest catalog data.
        $catalog = is_array($item['clinical_catalog_item'] ?? null) ? $item['clinical_catalog_item'] : null;
        $catalogMeta = is_array($catalog['metadata'] ?? null) ? $catalog['metadata'] : [];
        $catalogCodes = is_array($catalog['codes'] ?? null) ? $catalog['codes'] : [];
        $hasCatalogLink = ($item['clinical_catalog_item_id'] ?? null) !== null && $catalog !== null;

        return [
            'id' => $item['id'] ?? null,
            'itemCode' => $item['item_code'] ?? null,
            'msdCode' => $item['msd_code'] ?? null,
            'nhifCode' => $item['nhif_code'] ?? null,
            'barcode' => $item['barcode'] ?? null,
            'codes' => $hasCatalogLink && $catalogCodes !== [] ? $catalogCodes : (is_array($item['codes'] ?? null) ? $item['codes'] : null),
            'standardsWarnings' => app(StandardsCodeSupport::class)->warningsForInventoryItem($item),
            'clinicalCatalogItemId' => $item['clinical_catalog_item_id'] ?? null,
            'itemName' => $hasCatalogLink ? trim((string) ($catalog['name'] ?? '')) : ($item['item_name'] ?? null),
            'genericName' => $hasCatalogLink
                ? ($catalog['generic_name'] ?? $catalogMeta['genericName'] ?? $catalogMeta['generic_name'] ?? null)
                : null,
            'dosageForm' => $hasCatalogLink
                ? ($catalog['dosage_form'] ?? $catalogMeta['dosageForm'] ?? $catalogMeta['dosage_form'] ?? null)
                : null,
            'strength' => $hasCatalogLink
                ? ($catalog['strength'] ?? $catalogMeta['strength'] ?? null)
                : null,
            'category' => $item['category'] ?? null,
            'subcategory' => $hasCatalogLink
                ? ($catalog['category'] ?? $item['subcategory'] ?? null)
                : ($item['subcategory'] ?? null),
            'venClassification' => $item['ven_classification'] ?? null,
            'abcClassification' => $item['abc_classification'] ?? null,
            'unit' => $hasCatalogLink
                ? ($catalogMeta['stockUnit'] ?? $catalogMeta['stock_unit'] ?? $catalog['unit'] ?? $item['unit'] ?? null)
                : ($item['unit'] ?? null),
            'dispensingUnit' => $hasCatalogLink
                ? ($catalogMeta['dispensingUnit'] ?? $catalogMeta['dispensing_unit'] ?? $catalog['unit'] ?? $item['dispensing_unit'] ?? null)
                : ($item['dispensing_unit'] ?? null),
            'conversionFactor' => $hasCatalogLink
                ? ($catalogMeta['conversionFactor'] ?? $catalogMeta['conversion_factor'] ?? $item['conversion_factor'] ?? null)
                : ($item['conversion_factor'] ?? null),
            'binLocation' => $item['bin_location'] ?? null,
            'manufacturer' => $item['manufacturer'] ?? null,
            'storageConditions' => $item['storage_conditions'] ?? null,
            'requiresColdChain' => (bool) ($item['requires_cold_chain'] ?? false),
            // is_controlled_substance/controlled_substance_schedule dropped from
            // inventory_items in Phase 3 -- same reasoning as generic_name above.
            'isControlledSubstance' => $hasCatalogLink && (bool) ($catalog['is_controlled_substance'] ?? false),
            'controlledSubstanceSchedule' => $hasCatalogLink ? ($catalog['controlled_substance_schedule'] ?? null) : null,
            'defaultWarehouseId' => $item['default_warehouse_id'] ?? null,
            'defaultSupplierId' => $item['default_supplier_id'] ?? null,
            'currentStock' => $item['current_stock'] ?? null,
            'reorderLevel' => $item['reorder_level'] ?? null,
            'maxStockLevel' => $item['max_stock_level'] ?? null,
            'movementCount' => (int) ($item['stock_movements_count'] ?? 0),
            'openingStockMovementCount' => (int) ($item['opening_stock_movements_count'] ?? 0),
            'status' => $item['status'] ?? null,
            'statusReason' => $item['status_reason'] ?? null,
            'stockState' => self::stockState($item),
            'createdAt' => $item['created_at'] ?? null,
            'updatedAt' => $item['updated_at'] ?? null,
        ];
    }

    private static function stockState(array $item): string
    {
        $current = (float) ($item['current_stock'] ?? 0);
        $reorder = (float) ($item['reorder_level'] ?? 0);

        if ($current <= 0) {
            return 'out_of_stock';
        }

        if ($current <= $reorder) {
            return 'low_stock';
        }

        return 'healthy';
    }
}
