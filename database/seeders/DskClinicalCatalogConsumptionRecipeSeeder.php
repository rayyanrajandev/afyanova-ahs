<?php

namespace Database\Seeders;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogConsumptionRecipeItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskClinicalCatalogConsumptionRecipeSeeder extends Seeder
{
    /**
     * @var array<string, string>|null
     */
    private ?array $inventoryItemIds = null;

    /**
     * @var array<string, string>|null
     */
    private ?array $catalogItemIds = null;

    /**
     * Map of catalog codes to their recipes.
     * Each recipe entry: [inventory_item_code, quantity_per_order, unit, consumption_stage, waste_factor_percent?]
     */
    private function getRecipes(): array
    {
        return [
            // ========================
            // LAB TESTS
            // ========================

            'LAB-MRDT-001' => [
                ['INV-LAB-MRDT-001', 1, 'test', 'processing'],
                ['INV-LAB-LANCET-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-EDTA-CAP-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HIV-001' => [
                ['INV-LAB-HIV-KIT-001', 1, 'test', 'processing'],
                ['INV-LAB-EDTA-CAP-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HPYLORI-001' => [
                ['INV-LAB-H-PYLORI-001', 1, 'test', 'processing'],
                ['INV-LAB-EDTA-CAP-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-VDRL-001' => [
                ['INV-LAB-VDRL-001', 1, 'ml', 'processing'],
                ['INV-LAB-RED-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HB-001' => [
                ['INV-LAB-HB-001', 1, 'strip', 'processing'],
                ['INV-LAB-EDTA-CAP-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-LANCET-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-RBG-001' => [
                ['INV-LAB-RBG-001', 1, 'strip', 'processing'],
                ['INV-MEDCO-LANC-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-ABO-001' => [
                ['INV-LAB-ABO-ANTISERA-001', 1, 'set', 'processing'],
                ['INV-LAB-PURPLE-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-URINE-001' => [
                ['INV-LAB-URINE-DIPSTICK-001', 1, 'strip', 'processing'],
                ['INV-LAB-URINE-CONTAINER-001', 1, 'piece', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-STOOL-001' => [
                ['INV-LAB-STOOL-CONTAINER-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-STOOL-APPLICATOR-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SLIDES-001', 1, 'piece', 'processing'],
                ['INV-LAB-NS-100-001', 1, 'ml', 'processing'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-ESR-001' => [
                ['INV-LAB-ESR-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HVS-001' => [
                ['INV-LAB-HVS-SWAB-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SLIDES-001', 1, 'piece', 'processing'],
                ['INV-LAB-NS-100-001', 1, 'ml', 'processing'],
                ['INV-LAB-KOH-001', 1, 'ml', 'processing'],
                ['INV-MEDCO-EGLV-001', 2, 'pair', 'sample_collection'],
            ],
            'LAB-UPT-001' => [
                ['INV-LAB-UPT-001', 1, 'test', 'processing'],
                ['INV-LAB-URINE-CONTAINER-001', 1, 'piece', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HBSAG-001' => [
                ['INV-LAB-HBSAG-001', 1, 'test', 'processing'],
                ['INV-LAB-RED-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-WIDAL-001' => [
                ['INV-LAB-WIDAL-ANTIGEN-001', 1, 'set', 'processing'],
                ['INV-LAB-RED-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-CHO-001' => [
                ['INV-LAB-LIPID-REAGENT-001', 1, 'test', 'processing'],
                ['INV-LAB-RED-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],
            'LAB-URA-001' => [
                ['INV-LAB-CREAT-REAGENT-001', 1, 'test', 'processing'],
                ['INV-LAB-RED-TUBE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'sample_collection'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'sample_collection'],
            ],

            // ========================
            // RADIOLOGY PROCEDURES
            // ========================

            'RAD-US-ABD-001' => [
                ['INV-RAD-US-GEL-001', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER-001', 1, 'piece', 'processing'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'processing'],
            ],
            'RAD-US-PEL-001' => [
                ['INV-RAD-US-GEL-001', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER-001', 1, 'piece', 'processing'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'processing'],
            ],

            // ========================
            // CLINICAL PROCEDURES
            // ========================

            'CLN-WOUND-CLEAN-001' => [
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 2, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-WOUND-DRESS-001' => [
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-MEDCO-TAPE-001', 1, 'strip', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-BURN-DRESS-001' => [
                ['INV-MEDCO-GAUZE-001', 4, 'piece', 'procedure_completion'],
                ['MED-SILVEX-10CREAM', 5, 'g', 'procedure_completion'],
                ['INV-MEDCO-BANDAGE-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-SGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-WOUND-DEBRIDE-001' => [
                ['INV-MEDCO-GAUZE-001', 4, 'piece', 'procedure_completion'],
                ['INV-MEDCO-SCALPEL-15-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 2, 'ml', 'procedure_completion'],
                ['MEDCO-SYR5-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-21G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-SUTURE-001' => [
                ['INV-MEDCO-SUTURE-SILK-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 2, 'ml', 'procedure_completion'],
                ['MEDCO-SYR5-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-23G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-SUTURE-REMOVE-001' => [
                ['INV-MEDCO-SUTURE-REMOVAL-001', 1, 'set', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-INC-DRAIN-001' => [
                ['INV-MEDCO-SCALPEL-11-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 4, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 3, 'ml', 'procedure_completion'],
                ['MEDCO-SYR5-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-21G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-SGLV-001', 1, 'pair', 'procedure_completion'],
                ['INV-MEDCO-DRAIN-001', 1, 'piece', 'procedure_completion'],
            ],
            'CLN-PARONYCHIA-001' => [
                ['INV-MEDCO-SCALPEL-11-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-FB-REMOVAL-001' => [
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 1, 'ml', 'procedure_completion'],
                ['MEDCO-SYR2-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-23G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-MVA-001' => [
                ['INV-MEDCO-MVA-SYRINGE-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-MVA-CANNULA-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-SPECULUM-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-SGLV-001', 1, 'pair', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 10, 'ml', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 4, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 5, 'ml', 'procedure_completion'],
                ['MEDCO-SYR10-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-21G-001', 1, 'piece', 'procedure_completion'],
            ],
            'CLN-INJECTION-IM-001' => [
                ['MEDCO-SYR2-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-23G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-INJECTION-IV-001' => [
                ['MEDCO-SYR5-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-21G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-TOURNIQUET-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-INJECTION-SC-001' => [
                ['INV-MEDCO-SYRINGE-1ML-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-26G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-INJECTION-ID-001' => [
                ['INV-MEDCO-SYRINGE-1ML-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-26G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-IV-CANNULA-001' => [
                ['INV-MEDCO-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-TRANSPARENT-DRESS-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-TOURNIQUET-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
                ['INV-MEDCO-NS-FLUSH-001', 1, 'piece', 'procedure_completion'],
            ],
            'CLN-IV-FLUID-001' => [
                ['INV-MEDCO-IVSET-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-TRANSPARENT-DRESS-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NS-FLUSH-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-BLOOD-TRANSFUSION-001' => [
                ['INV-MEDCO-BLOOD-GIVING-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-CANN-18G', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-TRANSPARENT-DRESS-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NS-FLUSH-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-EMERG-MED-001' => [
                ['MEDCO-SYR5-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-21G-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-PATIENT-STABILIZE-001' => [
                ['INV-MEDCO-OXYGEN-MASK-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-IVSET-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 2, 'pair', 'procedure_completion'],
            ],
            'CLN-IMPLANT-INSERT-001' => [
                ['INV-MEDCO-IMPLANT-ROD-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 2, 'ml', 'procedure_completion'],
                ['MEDCO-SYR2-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-23G-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-SGLV-001', 1, 'pair', 'procedure_completion'],
                ['INV-MEDCO-TAPE-001', 1, 'strip', 'procedure_completion'],
            ],
            'CLN-IMPLANT-REMOVE-001' => [
                ['INV-MEDCO-SCALPEL-11-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-LIDOCAINE-001', 2, 'ml', 'procedure_completion'],
                ['MEDCO-SYR2-1PC', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-NEEDLE-23G-001', 1, 'piece', 'procedure_completion'],
                ['INV-MEDCO-GAUZE-001', 2, 'piece', 'procedure_completion'],
                ['INV-LAB-POVIDONE-IODINE-001', 5, 'ml', 'procedure_completion'],
                ['INV-MEDCO-SGLV-001', 1, 'pair', 'procedure_completion'],
                ['INV-MEDCO-TAPE-001', 1, 'strip', 'procedure_completion'],
            ],
            'CLN-HTN-FOLLOWUP-001' => [
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-DM-FOLLOWUP-001' => [
                ['INV-LAB-RBG-001', 1, 'strip', 'procedure_completion'],
                ['INV-MEDCO-LANC-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-COTTON-001', 1, 'piece', 'procedure_completion'],
                ['INV-LAB-SPIRIT-001', 1, 'ml', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
            'CLN-ASTHMA-NEB-001' => [
                ['INV-MEDCO-NEB-MASK-001', 1, 'piece', 'procedure_completion'],
                ['MED-SALB-NEB25', 1, 'each', 'procedure_completion'],
                ['INV-MEDCO-EGLV-001', 1, 'pair', 'procedure_completion'],
            ],
        ];
    }

    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $this->loadIds($facility);
        $recipes = $this->getRecipes();

        $count = 0;
        $warnings = [];

        foreach ($recipes as $catalogCode => $lines) {
            if (!isset($this->catalogItemIds[$catalogCode])) {
                $warnings[] = "Catalog item '{$catalogCode}' not found.";
                continue;
            }

            $catalogId = $this->catalogItemIds[$catalogCode];

            foreach ($lines as $line) {
                [$invCode, $qty, $unit, $stage] = $line;
                $waste = $line[4] ?? 0;

                if (!isset($this->inventoryItemIds[$invCode])) {
                    $warnings[] = "Inventory item '{$invCode}' (consumed by '{$catalogCode}') not found.";
                    continue;
                }

                $invId = $this->inventoryItemIds[$invCode];

                ClinicalCatalogConsumptionRecipeItemModel::firstOrCreate(
                    [
                        'clinical_catalog_item_id' => $catalogId,
                        'inventory_item_id' => $invId,
                    ],
                    [
                        'tenant_id' => $facility->tenant_id,
                        'facility_id' => $facility->id,
                        'quantity_per_order' => $qty,
                        'unit' => $unit,
                        'waste_factor_percent' => $waste,
                        'consumption_stage' => $stage,
                        'is_active' => true,
                    ],
                );

                $count++;
            }
        }

        foreach ($warnings as $w) {
            $this->command?->warn($w);
        }

        $this->command?->info("Seeded {$count} consumption recipe lines for DSK Dispensary.");
    }

    private function loadIds(FacilityModel $facility): void
    {
        $this->inventoryItemIds = InventoryItemModel::where('facility_id', $facility->id)
            ->pluck('id', 'item_code')
            ->toArray();

        $this->catalogItemIds = ClinicalCatalogItemModel::where('facility_id', $facility->id)
            ->pluck('id', 'code')
            ->toArray();
    }
}
