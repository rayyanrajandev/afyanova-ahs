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
        'LAB-PAR-MRDT' => 3000,
        'LAB-SER-HIV-RDT' => 3000,
        'LAB-SER-HPYLORI-RDT' => 5000,
        'LAB-SER-SYPHILIS-RPR' => 4000,
        'LAB-HEM-HB' => 3000,
        'LAB-BIO-GLUCOSE-RBG' => 2000,
        'LAB-BB-ABO-RH' => 5000,
        'LAB-URI-ROUTINE' => 3000,
        'LAB-PAR-STOOL-ROUTINE' => 3000,
        'LAB-HEM-ESR' => 5000,
        'LAB-MIC-HVS' => 5000,
        'LAB-SER-UPT' => 3000,
        'LAB-SER-HBSAG-RDT' => 5000,
        'LAB-SER-WIDAL' => 8000,
        'LAB-BIO-LIPID-CHO' => 7000,
        'LAB-BIO-RENAL-URIC' => 6000,
        'LAB-SER-HCV-RDT' => 5000,
    ];

    private array $radiologyPrices = [
        'RAD-US-ABDOMEN' => 20000,
        'RAD-US-PELVIS' => 20000,
        'RAD-US-OBSTETRIC' => 25000,
        'RAD-US-THYROID' => 15000,
        'RAD-US-SCROTAL' => 15000,
    ];

    private array $clinicalPrices = [
        'PROC-NURS-WOUND-CLEAN' => 5000,
        'PROC-NURS-WOUND-DRESS' => 5000,
        'PROC-NURS-BURN-DRESS' => 8000,
        'PROC-SURG-SUTURE-MINOR' => 15000,
        'PROC-SURG-SUTURE-REMOVE' => 5000,
        'PROC-SURG-INC-DRAIN' => 10000,
        'PROC-SURG-PARONYCHIA' => 8000,
        'PROC-SURG-FB-REMOVAL' => 10000,
        'PROC-SURG-WOUND-DEBRIDE' => 12000,
        'PROC-NURS-INJECT-IM' => 3000,
        'PROC-NURS-INJECT-IV' => 4000,
        'PROC-NURS-INJECT-SC' => 3000,
        'PROC-NURS-INJECT-ID' => 3000,
        'PROC-LINE-IV-CANNULA' => 5000,
        'PROC-LINE-IV-FLUID' => 8000,
        'PROC-NURS-TRANSFUSION-MONITOR' => 15000,
        'PROC-EMERG-MED-ADMIN' => 5000,
        'PROC-OBG-MVA' => 25000,
        'PROC-OBG-IMPLANT-INSERT' => 20000,
        'PROC-OBG-IMPLANT-REMOVE' => 15000,
        'PROC-NURS-HTN-FOLLOWUP' => 5000,
        'PROC-NURS-DM-FOLLOWUP' => 5000,
        'PROC-RESP-NEBULIZE' => 8000,
        'PROC-EMERG-STABILIZE' => 10000,
        'PROC-MED-REFERRAL' => 3000,
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
            // Analgesics & Anti-Inflammatory
            'MED-PARA-500TAB' => 100, 'MED-PARA-100SYR' => 3000,
            'MED-PARA-IV100' => 8000, 'MED-PARA-SUP125' => 500,
            'MED-IBUP-200TAB' => 200, 'MED-IBUP-100SYR' => 3500,
            'MED-DICLO-3IM' => 1500, 'MED-DICLO-20GEL' => 3000,
            'MED-ACECL-100TAB' => 300, 'MED-PIROX-20CAP' => 200,
            'MED-MELOX-15TAB' => 300, 'MED-DUOCO-360TAB' => 500,
            'MED-TERMID-100SYR' => 4000,
            'MED-TRAM-50CAP' => 500, 'MED-TRAM-2IV' => 2500,

            // Antibiotics
            'MED-AMOX-250CAP' => 200, 'MED-AMOX-100SYR' => 3000,
            'MED-AMOCL-625TAB' => 800, 'MED-AMOCL-375TAB' => 600,
            'MED-AMOCL-100SYR' => 5000, 'MED-AMOCL-12IV' => 6000,
            'MED-AMPCLOX-500CAP' => 300, 'MED-AMPCLX-100SYR' => 4000,
            'MED-AMPCLXN-06SYR' => 3500, 'MED-AMPCLOX-500IV' => 3000,
            'MED-AMPIC-250IV' => 2000,
            'MED-CEPH-250CAP' => 300, 'MED-CEPH-100SYR' => 4000,
            'MED-CEFAD-500CAP' => 500, 'MED-CEFIX-400TAB' => 1000,
            'MED-CEFTR-1IV' => 5000, 'MED-CEFTRS-15IV' => 7000,
            'MED-CEFOT-12IV' => 6000,
            'MED-CIPRO-500TAB' => 200, 'MED-CIPRO-EYEDROP' => 3000,
            'MED-CIPRO-IV100' => 6000, 'MED-CIPT-600TAB' => 500,
            'MED-AZITH-500TAB' => 1000, 'MED-AZITH-250TAB' => 700,
            'MED-AZITH-30SYR' => 4000, 'MED-CLARI-500TAB' => 800,
            'MED-ERYTH-250TAB' => 200, 'MED-ERYTH-100SYR' => 3500,
            'MED-DOXY-100CAP' => 200,
            'MED-COTRI-480TAB' => 100, 'MED-COTRI-100SYR' => 2500,
            'MED-GENT-40IM' => 1500,
            'MED-PVPC-250TAB' => 200,
            'MED-PENAD-24IM' => 5000, 'MED-BENZP-5MU' => 4000,
            'MED-NITF-100TAB' => 300,
            'MED-MUPI-10OINT' => 4000, 'MED-TETRA-15OINT' => 2000,
            'MED-SILVEX-10CREAM' => 5000,

            // Antimalarials
            'MED-ALUME-12TAB' => 1500, 'MED-ALUME-24TAB' => 2500,
            'MED-ALUME-6TAB' => 3000, 'MED-AL-22SYR' => 4000,
            'MED-LONART-24SYR' => 5000,
            'MED-ARTE-80IM' => 3000, 'MED-ARTSN-60IV' => 5000,
            'MED-ARTSN-120IV' => 8000, 'MED-MALAF-525TAB' => 500,

            // Antifungals
            'MED-FLUC-150CAP' => 1000, 'MED-FLUC-IV100' => 8000,
            'MED-KETO-30CREAM' => 3000, 'MED-CLTR-15CREAM' => 2000,
            'MED-CLTR-100PESS' => 1500, 'MED-MICG-400PESS' => 2000,
            'MED-GYNEX-PESS' => 2500, 'MED-NYST-30SYR' => 3000,
            'MED-GRIS-500TAB' => 300, 'MED-WHFL-20OINT' => 1500,

            // Antivirals
            'MED-ACICV-200TAB' => 500, 'MED-ACICV-10CREAM' => 3000,

            // Anthelmintics & Antiprotozoals
            'MED-ALBEN-200TAB' => 300, 'MED-ALBEN-10SYR' => 2500,
            'MED-MEBEN-100TAB' => 200,
            'MED-METRO-200TAB' => 100, 'MED-METRO-100SYR' => 2500,
            'MED-METRO-IV100' => 4000, 'MED-METMI-200TAB' => 500,
            'MED-TINI-500TAB' => 300,

            // Cardiovascular
            'MED-ATEN-50TAB' => 200, 'MED-CAPT-25TAB' => 200,
            'MED-NIFE-20TAB' => 200, 'MED-FURO-40TAB' => 200,
            'MED-FURO-10IV' => 2000, 'MED-HYDR-25TAB' => 300,
            'MED-BENDFT-5TAB' => 200, 'MED-ASPJ-75TAB' => 100,
            'MED-ADREN-1ML' => 2000, 'MED-ATROP-1IV' => 1500,
            'MED-AMLO-5TAB' => 200, 'MED-AMLO-10TAB' => 300,
            'MED-ENAL-5TAB' => 200, 'MED-ENAL-10TAB' => 300,

            // Respiratory
            'MED-SALB-NEB25' => 1500, 'MED-AMINO-100TAB' => 200,
            'MED-AMINO-250IV' => 3000,
            'MED-CETIR-10TAB' => 200, 'MED-CETIR-60SYR' => 3000,
            'MED-LORA-10TAB' => 200, 'MED-MONT-10TAB' => 500,
            'MED-MONT-5TAB' => 400,
            'MED-MUCAD-100SYR' => 4000, 'MED-MUCPA-100SYR' => 3500,
            'MED-CODRIL-100SYR' => 4000, 'MED-COUGH-100SYR' => 3500,
            'MED-DRCOLD-100SYR' => 4000, 'MED-ZECUF-100SYR' => 4000,
            'MED-NASAL-ADULT' => 2000, 'MED-NASAL-PAED' => 2000,

            // Gastrointestinal
            'MED-OMPZ-20CAP' => 200, 'MED-PANTO-40TAB' => 300,
            'MED-PANTO-40IV' => 5000,
            'MED-LOPER-2TAB' => 200, 'MED-LOPER-5CAP' => 300,
            'MED-METOC-10TAB' => 200, 'MED-METOC-2IV' => 1500,
            'MED-HYOSC-10TAB' => 300, 'MED-HYOSC-10IV' => 2500,
            'MED-BISAC-5TAB' => 200, 'MED-LACT-100SYR' => 4000,
            'MED-ANTAC-100SYR' => 3000, 'MED-CMAG-250TAB' => 100,
            'MED-BELLAD-100SYR' => 3000, 'MED-GRIPE-100SYR' => 2500,
            'MED-CITAL-100SYR' => 3000,

            // Endocrine & Metabolic
            'MED-METF-500TAB' => 200, 'MED-METGLIM-501TAB' => 500,
            'MED-GLIB-5TAB' => 200,

            // Dermatological & Corticosteroids
            'MED-PRED-5TAB' => 200,
            'MED-HYDC-15CREAM' => 2000, 'MED-HYDC-100IV' => 4000,
            'MED-CLOB-10CREAM' => 3000, 'MED-SKDERM-30CREAM' => 4000,
            'MED-GENTR-10CREAM' => 3500, 'MED-BETBZ-30CREAM' => 3000,
            'MED-DEXAM-4IV' => 2000, 'MED-TRIAM-40IM' => 5000,
            'MED-BURN-30CREAM' => 5000, 'MED-BPO-20GEL' => 3000,
            'MED-BBE-100LOT' => 3000, 'MED-CALZ-100LOT' => 2500,

            // Haematological
            'MED-FERSUL-200TAB' => 100, 'MED-FOLIC-5TAB' => 100,
            'MED-FERR-162CAP' => 300, 'MED-IRONS-20IV' => 8000,
            'MED-TRANE-5IV' => 4000,
            'MED-GLOBZ-200SYR' => 5000, 'MED-HEMOV-200SYR' => 5000,
            'MED-HEMAT-200SYR' => 5000, 'MED-SKTONE-100SYR' => 4000,
            'MED-MUMFER-150SYR' => 4000,

            // Hormonal & Contraceptives
            'MED-MEDRO-150IM' => 3000, 'MED-NORE-5TAB' => 300,
            'MED-DUPH-10TAB' => 1000, 'MED-MISO-200TAB' => 1500,
            'MED-OXYT-10IU' => 2000, 'MED-MGSO4-50IV' => 3000,

            // Mental Health & Psychiatric
            'MED-DIAZ-5TAB' => 200, 'MED-DIAZ-10IV' => 2000,
            'MED-PROM-25TAB' => 200, 'MED-PROM-2IM' => 2000,

            // Neurological
            'MED-PREG-75CAP' => 500, 'MED-BACL-10TAB' => 300,
            'MED-TIZA-4TAB' => 300, 'MED-NEURO-300TAB' => 500,

            // Eye & ENT
            'MED-CHLOR-EYEDROP' => 2000, 'MED-CHLOR-EYEINT' => 2500,
            'MED-GENT-EYEDROP' => 3000,
            'MED-DEXNEO-EYEDROP' => 4000, 'MED-DEXP-EYEDROP' => 3500,
            'MED-BORIC-EARDROP' => 2000,

            // IV Fluids & Nutritional
            'MED-NS-IV500' => 3000, 'MED-D5-IV500' => 3000,
            'MED-DNS-IV500' => 3500, 'MED-RL-IV500' => 3500,
            'MED-GLUC-80POW' => 500, 'MED-ORS-POW' => 500,

            // Vitamins & Minerals
            'MED-MULTV-TAB' => 100, 'MED-MULTV-100SYR' => 3000,
            'MED-VITBC-10TAB' => 100, 'MED-VITBC-100SYR' => 2500,
            'MED-VITB-10IM' => 2000,
            'MED-ZNSUL-20TAB' => 200, 'MED-ZNSUL-100SYR' => 2500,

            // Immunological / Vaccines
            'MED-TETAN-05IM' => 3000,

            // Urological
            'MED-TAMS-04CAP' => 500,
        ];

        return $prices[$item->code] ?? 1000;
    }
}
