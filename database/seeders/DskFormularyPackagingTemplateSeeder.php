<?php

namespace Database\Seeders;

use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemPackagingTemplateModel;
use Illuminate\Database\Seeder;

class DskFormularyPackagingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $items = ClinicalCatalogItemModel::where('catalog_type', 'formulary_item')->get();

        if ($items->isEmpty()) {
            $this->command?->error('No formulary items found. Run DskFormularyClinicalCatalogSeeder first.');
            return;
        }

        $created = 0;

        foreach ($items as $item) {
            $meta = $item->metadata;
            if (!is_array($meta)) {
                continue;
            }

            $stockUnit = $meta['stockUnit'] ?? 'each';
            $purchaseUnit = $meta['purchaseUnit'] ?? 'box';
            $purchaseQty = (int) ($meta['purchaseUnitQuantity'] ?? 1);

            // Base unit: 1 unit of stockUnit (e.g. 1 tablet)
            $base = ClinicalCatalogItemPackagingTemplateModel::updateOrCreate(
                [
                    'clinical_catalog_item_id' => $item->id,
                    'unit_name' => $stockUnit,
                ],
                [
                    'base_quantity' => 1,
                    'is_base_unit' => true,
                    'is_default_sales_unit' => true,
                    'is_default_purchase_unit' => ($stockUnit === $purchaseUnit),
                ],
            );
            if ($base->wasRecentlyCreated) {
                $created++;
            }

            // Purchase unit (e.g. box of 100): only if different from stock unit
            if ($purchaseUnit !== $stockUnit && $purchaseQty > 1) {
                $purchase = ClinicalCatalogItemPackagingTemplateModel::updateOrCreate(
                    [
                        'clinical_catalog_item_id' => $item->id,
                        'unit_name' => $purchaseUnit,
                    ],
                    [
                        'base_quantity' => $purchaseQty,
                        'is_default_purchase_unit' => true,
                        'is_base_unit' => false,
                        'is_default_sales_unit' => false,
                    ],
                );
                if ($purchase->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        $itemCount = $items->count();
        $this->command?->info("Created {$created} packaging templates across {$itemCount} formulary items.");
    }
}
