<?php

namespace Database\Seeders;

use App\Modules\Billing\Infrastructure\Models\PriceBookEntryModel;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskChargeableItemsSeeder extends Seeder
{
    private array $labPrices = [
        'LAB-MRDT-001' => 3000,
        'LAB-HIV-RDT-001' => 3000,
        'LAB-HPYLORI-001' => 5000,
        'LAB-VDRL-001' => 4000,
        'LAB-HB-001' => 3000,
        'LAB-RBG-001' => 2000,
        'LAB-ABO-001' => 5000,
        'LAB-URINALYSIS-001' => 3000,
        'LAB-STOOL-001' => 3000,
        'LAB-ESR-001' => 5000,
        'LAB-HVS-001' => 5000,
        'LAB-UPT-001' => 3000,
        'LAB-HBSAG-001' => 5000,
        'LAB-WIDAL-001' => 8000,
        'LAB-CHOLESTEROL-001' => 7000,
        'LAB-URIC-001' => 6000,
    ];

    private array $radiologyPrices = [
        'RAD-XRAY-CHEST-001' => 15000,
        'RAD-XRAY-LIMB-001' => 12000,
    ];

    private array $clinicalPrices = [
        'CLN-WOUND-CLEAN-001' => 5000,
        'CLN-WOUND-DRESS-001' => 5000,
        'CLN-BURN-DRESS-001' => 8000,
        'CLN-SUTURE-001' => 15000,
        'CLN-SUTURE-REMOVE-001' => 5000,
        'CLN-INC-DRAIN-001' => 10000,
        'CLN-PARONYCHIA-001' => 8000,
        'CLN-FB-REMOVAL-001' => 10000,
        'CLN-WOUND-DEBRIDE-001' => 12000,
        'CLN-INJECTION-IM-001' => 3000,
        'CLN-INJECTION-IV-001' => 4000,
        'CLN-INJECTION-SC-001' => 3000,
        'CLN-INJECTION-ID-001' => 3000,
        'CLN-IV-CANNULA-001' => 5000,
        'CLN-IV-FLUID-001' => 8000,
        'CLN-BLOOD-TRANSFUSION-001' => 15000,
        'CLN-EMERG-MED-001' => 5000,
        'CLN-MVA-001' => 25000,
        'CLN-IMPLANT-INSERT-001' => 20000,
        'CLN-IMPLANT-REMOVE-001' => 15000,
        'CLN-HTN-FOLLOWUP-001' => 5000,
        'CLN-DM-FOLLOWUP-001' => 5000,
        'CLN-ASTHMA-NEB-001' => 8000,
        'CLN-PATIENT-STABILIZE-001' => 10000,
        'CLN-REFERRAL-001' => 3000,
    ];

    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $catalogItems = ClinicalCatalogItemModel::where('facility_id', $facility->id)->get();

        if ($catalogItems->isEmpty()) {
            $this->command?->warn('No clinical catalog items found for DSK. Run catalog seeders first.');
            return;
        }

        $count = 0;
        $priceCount = 0;

        foreach ($catalogItems as $item) {
            $chargeModel = $item->catalog_type === 'formulary_item' ? 'per_unit' : 'flat';

            $price = $this->resolvePrice($item);

            $chargeable = ChargeableItemModel::firstOrCreate(
                [
                    'id' => $item->id,
                ],
                [
                    'clinical_catalog_item_id' => $item->id,
                    'tenant_id' => $facility->tenant_id,
                    'facility_id' => $facility->id,
                    'catalog_type' => $item->catalog_type,
                    'charge_model' => $chargeModel,
                    'code' => $item->code,
                    'name' => $item->name,
                    'department_id' => $item->department_id,
                    'category' => $item->category,
                    'default_unit' => $item->unit,
                    'status' => 'active',
                ],
            );

            if ($chargeable->wasRecentlyCreated) {
                $count++;
            }

            if ($price > 0) {
                $existingPrice = PriceBookEntryModel::where('chargeable_item_id', $chargeable->id)->exists();
                if (!$existingPrice) {
                    PriceBookEntryModel::create([
                        'chargeable_item_id' => $chargeable->id,
                        'tenant_id' => $facility->tenant_id,
                        'facility_id' => $facility->id,
                        'currency_code' => 'TZS',
                        'unit_price' => $price,
                        'status' => 'active',
                    ]);
                    $priceCount++;
                }
            }
        }

        $this->command?->info("Seeded {$count} chargeable items and {$priceCount} price book entries for DSK Dispensary.");
    }

    private function resolvePrice(ClinicalCatalogItemModel $item): float
    {
        $code = $item->code;

        return match ($item->catalog_type) {
            'lab_test' => $this->labPrices[$code] ?? 5000,
            'radiology_procedure' => $this->radiologyPrices[$code] ?? 20000,
            'clinical_procedure' => $this->clinicalPrices[$code] ?? 8000,
            'formulary_item' => $this->resolveFormularyPrice($item),
            default => 0,
        };
    }

    private function resolveFormularyPrice(ClinicalCatalogItemModel $item): float
    {
        $prices = [
            'FRM-PCM-500-001' => 500, 'FRM-PCM-120-001' => 300,
            'FRM-IBU-400-001' => 800, 'FRM-IBU-200-001' => 500,
            'FRM-DCL-50-001' => 1000, 'FRM-AMOX-500-001' => 500,
            'FRM-AMOX-250-001' => 400, 'FRM-AMOX-CLV-625-001' => 1500,
            'FRM-AMOX-CLV-312-001' => 1200, 'FRM-CIP-500-001' => 1000,
            'FRM-CIP-250-001' => 800, 'FRM-MET-500-001' => 500,
            'FRM-DOX-100-001' => 800, 'FRM-AZI-500-001' => 2000,
            'FRM-AZI-250-001' => 1500, 'FRM-CFX-200-001' => 1500,
            'FRM-CFX-400-001' => 2000, 'FRM-ERY-250-001' => 800,
            'FRM-CLN-300-001' => 1200, 'FRM-CTX-960-001' => 500,
            'FRM-CTX-240-001' => 300, 'FRM-ALB-400-001' => 500,
            'FRM-MEB-100-001' => 300, 'FRM-PZQ-600-001' => 1000,
            'FRM-ART-LUM-001' => 3000, 'FRM-ART-LUM-CHILD-001' => 1500,
            'FRM-QNN-300-001' => 2000, 'FRM-ART-IM-001' => 2000,
            'FRM-SP-001' => 500, 'FRM-CHLQ-001' => 3000,
            'FRM-PRM-250-001' => 1000, 'FRM-MTN-001' => 2000,
            'FRM-FLU-001' => 1500, 'FRM-NAD-001' => 1500,
            'FRM-FER-200-001' => 800, 'FRM-FOL-5-001' => 500,
            'FRM-B12-001' => 800, 'FRM-FEFOL-001' => 500,
            'FRM-ORS-001' => 500, 'FRM-ZNC-001' => 300,
            'FRM-VIT-A-001' => 500, 'FRM-VIT-D-001' => 800,
            'FRM-AMI-250-001' => 2000, 'FRM-AMI-500-001' => 3000,
            'FRM-GENT-80-001' => 1200, 'FRM-METRO-IV-001' => 2000,
            'FRM-CFT-1G-001' => 5000, 'FRM-AMP-500-001' => 1500,
            'FRM-BENZ-001' => 3000, 'FRM-PNC-001' => 2000,
            'FRM-DEC-001' => 3000, 'FRM-HAL-001' => 800,
            'FRM-LID-2-001' => 1500, 'FRM-ATR-001' => 500,
            'FRM-ADR-001' => 2000, 'FRM-DIA-10-001' => 1500,
            'FRM-NAX-001' => 3000, 'FRM-OXY-001' => 2000,
            'FRM-NAL-001' => 5000, 'FRM-FLUMA-001' => 8000,
            'FRM-DEX-8-001' => 2000, 'FRM-DEX-4-001' => 1500,
            'FRM-PRO-001' => 2000, 'FRM-FUR-40-001' => 500,
            'FRM-HYD-25-001' => 500, 'FRM-SALB-001' => 500,
            'FRM-BECL-001' => 2000, 'FRM-IPR-001' => 1500,
            'FRM-PRED-5-001' => 300, 'FRM-PRED-20-001' => 500,
            'FRM-RAN-150-001' => 500, 'FRM-OME-20-001' => 500,
            'FRM-MTL-10-001' => 800, 'FRM-HYSC-10-001' => 1500,
            'FRM-DIAZ-5-001' => 500, 'FRM-PHB-100-001' => 500,
            'FRM-CBZ-200-001' => 800, 'FRM-VAL-500-001' => 2000,
            'FRM-AMI-T-50-001' => 500, 'FRM-FLU-20-001' => 1000,
            'FRM-HAL-5MG-001' => 500, 'FRM-CHL-250-001' => 2000,
            'FRM-NGM-001' => 10000, 'FRM-DMPA-001' => 5000,
            'FRM-LNG-001' => 8000, 'FRM-ETG-001' => 5000,
            'FRM-ETS-001' => 15000, 'FRM-IUD-001' => 5000,
            'FRM-CON-M-001' => 500, 'FRM-OXY-10-001' => 1500,
            'FRM-MIS-200-001' => 3000, 'FRM-ERG-001' => 1000,
            'FRM-MAG-50-001' => 2000, 'FRM-NIF-10-001' => 500,
            'FRM-METH-250-001' => 3000, 'FRM-BET-12-001' => 3000,
            'FRM-DEX-IM-001' => 2000, 'FRM-AMO-001' => 5000,
            'FRM-NEOSIG-001' => 1000, 'FRM-ATR-OPH-001' => 2000,
            'FRM-KET-30-001' => 3000, 'FRM-PROP-1-001' => 5000,
            'FRM-SODA-001' => 3000, 'FRM-RINGER-001' => 5000,
            'FRM-D5W-001' => 4000, 'FRM-D5H-001' => 5000,
            'FRM-NS-001' => 3000, 'FRM-STREP-001' => 500,
            'FRM-HIB-001' => 5000, 'FRM-PCV-001' => 8000,
            'FRM-ROTA-001' => 6000, 'FRM-IPV-001' => 5000,
            'FRM-BCG-001' => 3000, 'FRM-MR-001' => 5000,
            'FRM-YF-001' => 5000, 'FRM-TT-001' => 3000,
            'FRM-HEP-B-001' => 5000, 'FRM-RAB-001' => 8000,
            'FRM-SER-001' => 10000, 'FRM-PPZ-50-001' => 500,
            'FRM-PPZ-25-001' => 300, 'FRM-PPZ-200-001' => 1000,
            'FRM-PPZ-SYR-001' => 300, 'FRM-CMZ-100-001' => 500,
            'FRM-CMZ-200-001' => 800, 'FRM-CMZ-SYR-001' => 500,
            'FRM-GLB-5-001' => 300, 'FRM-GLB-2-001' => 200,
            'FRM-GLZ-80-001' => 500, 'FRM-MET-850-001' => 300,
            'FRM-GLC-50-001' => 500, 'FRM-INS-R-001' => 10000,
            'FRM-INS-NPH-001' => 10000, 'FRM-HCTZ-25-001' => 300,
            'FRM-AML-5-001' => 500, 'FRM-AML-10-001' => 800,
            'FRM-ENL-5-001' => 500, 'FRM-ENL-10-001' => 800,
            'FRM-LOS-50-001' => 500, 'FRM-LOS-25-001' => 300,
            'FRM-NIF-30-001' => 500, 'FRM-MTN-50-001' => 500,
            'FRM-ATEN-50-001' => 500, 'FRM-ATEN-100-001' => 800,
            'FRM-PRO-40-001' => 500, 'FRM-SPIR-25-001' => 500,
            'FRM-DIG-0-25-001' => 300, 'FRM-WAR-5-001' => 1000,
            'FRM-ASA-75-001' => 200, 'FRM-ASA-300-001' => 300,
            'FRM-CLO-75-001' => 500, 'FRM-SMV-20-001' => 2000,
            'FRM-SMV-40-001' => 3000, 'FRM-SMV-10-001' => 1500,
            'FRM-FEN-5-001' => 800, 'FRM-LOR-10-001' => 1500,
            'FRM-CET-10-001' => 500, 'FRM-CET-SYR-001' => 500,
            'FRM-LRT-10-001' => 500, 'FRM-LRT-SYR-001' => 500,
            'FRM-CTC-1-001' => 2000, 'FRM-CTC-0-05-001' => 1500,
            'FRM-CLOT-1-001' => 2000, 'FRM-MICO-2-001' => 2000,
            'FRM-GRF-1-001' => 3000, 'FRM-KET-CR-001' => 2000,
            'FRM-ACY-200-001' => 1500, 'FRM-POD-001' => 3000,
            'FRM-SULF-10-001' => 3000, 'FRM-BEN-B-001' => 2000,
            'FRM-PER-5-001' => 3000, 'FRM-TET-1-001' => 2000,
            'FRM-PER-OPH-001' => 3000, 'FRM-CIP-OPH-001' => 3000,
            'FRM-TOB-OPH-001' => 4000, 'FRM-TET-OPH-001' => 2000,
            'FRM-LUB-001' => 3000, 'FRM-FLUO-001' => 5000,
            'FRM-OXY-M-001' => 5000, 'FRM-PARA-001' => 2000,
        ];

        return $prices[$item->code] ?? 1000;
    }
}
