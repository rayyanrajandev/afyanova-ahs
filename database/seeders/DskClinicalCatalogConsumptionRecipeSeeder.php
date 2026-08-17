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

            'LAB-PAR-MRDT' => [
                ['INV-LAB-KIT-MRDT', 1, 'test', 'processing'],
                ['INV-CONS-LANCET', 1, 'piece', 'sample_collection'],
                ['INV-LAB-EDTA-CAP', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-HIV-RDT' => [
                ['INV-LAB-KIT-HIV', 1, 'test', 'processing'],
                ['INV-LAB-EDTA-CAP', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-HPYLORI-RDT' => [
                ['INV-LAB-KIT-HPYLORI', 1, 'test', 'processing'],
                ['INV-LAB-EDTA-CAP', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-SYPHILIS-RPR' => [
                ['INV-LAB-KIT-VDRL', 1, 'test', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HEM-HB' => [
                ['INV-LAB-STRIP-HB', 1, 'strip', 'processing'],
                ['INV-LAB-EDTA-CAP', 1, 'piece', 'sample_collection'],
                ['INV-CONS-LANCET', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-BIO-GLUCOSE-RBG' => [
                ['INV-LAB-STRIP-RBG', 1, 'strip', 'processing'],
                ['INV-CONS-LANCET', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-BB-ABO-RH' => [
                ['INV-LAB-ABO-ANTISERA', 1, 'set', 'processing'],
                ['INV-LAB-TUBE-PURPLE', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-URI-ROUTINE' => [
                ['INV-LAB-STRIP-URINE-DIP', 1, 'strip', 'processing'],
                ['INV-LAB-CONT-URINE', 1, 'piece', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-PAR-STOOL-ROUTINE' => [
                ['INV-LAB-CONT-STOOL', 1, 'piece', 'sample_collection'],
                ['INV-LAB-STOOL-SPATULA', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SLIDES', 1, 'piece', 'processing'],
                ['INV-LAB-NS-WET-MOUNT', 1, 'ml', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-HEM-ESR' => [
                ['INV-LAB-TUBE-ESR', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-MIC-HVS' => [
                ['INV-LAB-HVS-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-LAB-SLIDES', 1, 'piece', 'processing'],
                ['INV-LAB-NS-WET-MOUNT', 1, 'ml', 'processing'],
                ['INV-LAB-KOH-SOL', 1, 'ml', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 2, 'pair', 'sample_collection'],
            ],
            'LAB-SER-UPT' => [
                ['INV-LAB-KIT-UPT', 1, 'test', 'processing'],
                ['INV-LAB-CONT-URINE', 1, 'piece', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-HBSAG-RDT' => [
                ['INV-LAB-KIT-HBSAG', 1, 'test', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-WIDAL' => [
                ['INV-LAB-REAG-WIDAL', 1, 'set', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-BIO-LIPID-CHO' => [
                ['INV-LAB-STRIP-CHO', 1, 'strip', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-BIO-RENAL-URIC' => [
                ['INV-LAB-STRIP-URA', 1, 'strip', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],
            'LAB-SER-HCV-RDT' => [
                ['INV-LAB-KIT-HIV', 1, 'test', 'processing'],
                ['INV-LAB-TUBE-RED', 1, 'piece', 'sample_collection'],
                ['INV-LAB-VAC-NEEDLE', 1, 'piece', 'sample_collection'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'sample_collection'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'sample_collection'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'sample_collection'],
            ],

            // ========================
            // RADIOLOGY PROCEDURES
            // ========================

            'RAD-US-ABDOMEN' => [
                ['INV-RAD-US-GEL', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER', 1, 'piece', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'processing'],
            ],
            'RAD-US-PELVIS' => [
                ['INV-RAD-US-GEL', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER', 1, 'piece', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'processing'],
            ],
            'RAD-US-OBSTETRIC' => [
                ['INV-RAD-US-GEL', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER', 1, 'piece', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'processing'],
            ],
            'RAD-US-THYROID' => [
                ['INV-RAD-US-GEL', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER', 1, 'piece', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'processing'],
            ],
            'RAD-US-SCROTAL' => [
                ['INV-RAD-US-GEL', 5, 'ml', 'processing'],
                ['INV-RAD-PROBE-COVER', 1, 'piece', 'processing'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'processing'],
            ],

            // ========================
            // CLINICAL PROCEDURES
            // ========================

            'PROC-NURS-WOUND-CLEAN' => [
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-WOUND-DRESS' => [
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-TAPE-ZINC', 1, 'roll', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-BURN-DRESS' => [
                ['INV-CONS-GAUZE-4X4', 4, 'piece', 'procedure_completion'],
                ['MED-SILVEX-10CREAM', 5, 'tube', 'procedure_completion'],
                ['INV-CONS-BANDAGE-STERILE', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-SURG', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-SURG-WOUND-DEBRIDE' => [
                ['INV-CONS-GAUZE-4X4', 4, 'piece', 'procedure_completion'],
                ['INV-CONS-SCALPEL-15', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 2, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-5ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-21G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-SURG-SUTURE-MINOR' => [
                ['INV-CONS-SUTURE-SILK-30', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 2, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-5ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-23G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-SURG-SUTURE-REMOVE' => [
                ['INV-CONS-SUTURE-REMOVE-KIT', 1, 'set', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-SURG-INC-DRAIN' => [
                ['INV-CONS-SCALPEL-11', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 4, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 3, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-5ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-21G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-SURG', 1, 'pair', 'procedure_completion'],
                ['INV-CONS-DRAIN-WICK', 1, 'piece', 'procedure_completion'],
            ],
            'PROC-SURG-PARONYCHIA' => [
                ['INV-CONS-SCALPEL-11', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-SURG-FB-REMOVAL' => [
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 1, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-2ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-23G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-OBG-MVA' => [
                ['INV-CONS-MVA-SYRINGE', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-MVA-CANNULA', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPECULUM-DISP', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-SURG', 1, 'pair', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 10, 'ml', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 4, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 5, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-10ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-21G', 1, 'piece', 'procedure_completion'],
            ],
            'PROC-NURS-INJECT-IM' => [
                ['INV-CONS-SYR-2ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-23G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-INJECT-IV' => [
                ['INV-CONS-SYR-5ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-21G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-CONS-TOURNIQUET', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-INJECT-SC' => [
                ['INV-CONS-SYR-1ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-26G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-INJECT-ID' => [
                ['INV-CONS-SYR-1ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-26G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-LINE-IV-CANNULA' => [
                ['INV-CONS-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-TRANSP-DRESS', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-TOURNIQUET', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
                ['INV-CONS-NS-FLUSH-10ML', 1, 'piece', 'procedure_completion'],
            ],
            'PROC-LINE-IV-FLUID' => [
                ['INV-CONS-IV-SET', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-TRANSP-DRESS', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NS-FLUSH-10ML', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-TRANSFUSION-MONITOR' => [
                ['INV-CONS-BLOOD-GIVE-SET', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-CANN-18G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-TRANSP-DRESS', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NS-FLUSH-10ML', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-EMERG-MED-ADMIN' => [
                ['INV-CONS-SYR-5ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-21G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-EMERG-STABILIZE' => [
                ['INV-CONS-OXYGEN-MASK', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-CANN-22G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-IV-SET', 1, 'piece', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 2, 'pair', 'procedure_completion'],
            ],
            'PROC-OBG-IMPLANT-INSERT' => [
                ['INV-CONS-IMPLANON-ROD', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 2, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-2ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-23G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-SURG', 1, 'pair', 'procedure_completion'],
                ['INV-CONS-TAPE-ZINC', 1, 'roll', 'procedure_completion'],
            ],
            'PROC-OBG-IMPLANT-REMOVE' => [
                ['INV-CONS-SCALPEL-11', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-LIDO-1PCT-AMP', 2, 'ampoule', 'procedure_completion'],
                ['INV-CONS-SYR-2ML', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-NEEDLE-23G', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-GAUZE-4X4', 2, 'piece', 'procedure_completion'],
                ['INV-CONS-POVIDONE-IODINE', 5, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-SURG', 1, 'pair', 'procedure_completion'],
                ['INV-CONS-TAPE-ZINC', 1, 'roll', 'procedure_completion'],
            ],
            'PROC-NURS-HTN-FOLLOWUP' => [
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-NURS-DM-FOLLOWUP' => [
                ['INV-LAB-STRIP-RBG', 1, 'strip', 'procedure_completion'],
                ['INV-CONS-LANCET', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-COTTON-SWAB', 1, 'piece', 'procedure_completion'],
                ['INV-CONS-SPIRIT-PREP', 1, 'ml', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
            ],
            'PROC-RESP-NEBULIZE' => [
                ['INV-CONS-NEB-MASK', 1, 'piece', 'procedure_completion'],
                ['MED-SALB-NEB25', 1, 'each', 'procedure_completion'],
                ['INV-PPE-GLOVE-EXAM', 1, 'pair', 'procedure_completion'],
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
