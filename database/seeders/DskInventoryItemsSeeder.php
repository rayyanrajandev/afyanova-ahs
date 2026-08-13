<?php

namespace Database\Seeders;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemUnitModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventorySupplierModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskInventoryItemsSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $warehouse = InventoryWarehouseModel::where('facility_id', $facility->id)->where('warehouse_code', 'MAIN-PHARMACY')->first();
        $supplier = InventorySupplierModel::where('facility_id', $facility->id)->first();

        $items = [
            // ===== LABORATORY REAGENTS & SUPPLIES =====
            ['code' => 'INV-LAB-HIV-KIT-001', 'name' => 'HIV Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-MRDT-001', 'name' => 'Malaria Rapid Diagnostic Test Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-TYPHOID-OO1', 'name' => 'TYPHOID KIT', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 30, 'current_stock' => 150],
            ['code' => 'INV-LAB-H-PYLORI-001', 'name' => 'Helicobacter Pylori Antibody Test Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'INV-LAB-RED-TUBE-001', 'name' => 'RED TOP TUBE', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-LAB-PURPLE-TUBE-001', 'name' => 'Purple Top Tube', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-LAB-ESR-001', 'name' => 'ESR Tube', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-HB-001', 'name' => 'Haemoglobin Strip', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'strip', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-RBG-001', 'name' => 'Blood Sugar Strip', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'strip', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-URINE-CONTAINER-001', 'name' => 'Urine Container', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-STOOL-CONTAINER-001', 'name' => 'Stool Container', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 300],
            ['code' => 'INV-LAB-UPT-001', 'name' => 'Urine Pregnancy Test Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-SLIDES-001', 'name' => 'Microscope Slide', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'box', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-LAB-GIEMSA-001', 'name' => 'Giemsa Stain Solution 500Mls', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'bottle', 'reorder_level' => 2, 'current_stock' => 10],
            ['code' => 'INV-LAB-VDRL-001', 'name' => 'Syphilis Test Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 30, 'current_stock' => 150],
            ['code' => 'INV-LAB-HVS-001', 'name' => 'High Vaginal Swab', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-ABO-ANTISERA-001', 'name' => 'ABO Antisera Reagent', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'bottle', 'reorder_level' => 2, 'current_stock' => 5],
            ['code' => 'INV-LAB-HBSAG-001', 'name' => 'Hepatitis B Surface Antigen Kit', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'INV-LAB-CHOLESTEROL-001', 'name' => 'Cholesterol Strip', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'strip', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-UA-001', 'name' => 'Uric Acid Strip', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'strip', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-IMMERSION-001', 'name' => 'Immersion Oil', 'category' => 'laboratory', 'subcategory' => 'consumables', 'unit' => 'bottle', 'reorder_level' => 1, 'current_stock' => 5],

            // ===== MEDICAL CONSUMABLES =====
            ['code' => 'MEDCO-SYR2-1PC', 'name' => 'Syringe 2 ml (2 CC) 1 piece', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MEDCO-SYR10-1PC', 'name' => 'Syringe 10 ml (10 CC) 1 piece', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 300, 'current_stock' => 1500],
            ['code' => 'MEDCO-SYR5-1PC', 'name' => 'Syringe 5 ml (5 CC) 1 piece', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 400, 'current_stock' => 1500],
            ['code' => 'INV-MEDCO-CANN-22G', 'name' => 'Cannula 22G (Blue)', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-CANN-20G', 'name' => 'Cannula 20G (Pink)', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-CANN-24G', 'name' => 'Cannula 24G (Yellow)', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 300],
            ['code' => 'INV-MEDCO-CANN-18G', 'name' => 'Cannula 18G (Green)', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-IVSET-1PC', 'name' => 'Giving IV set 1 piece', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'MEDCO-SCALPV-1PC', 'name' => 'Scalp vein set 1 piece', 'category' => 'medical_consumable', 'subcategory' => 'catheters_tubes', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-LANC-001', 'name' => 'Blood Lancet', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-MEDCO-EGLV-001', 'name' => 'Examination Gloves', 'category' => 'medical_consumable', 'subcategory' => 'gloves_ppe', 'unit' => 'pair', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-MEDCO-SGLV-001', 'name' => 'Surgical Gloves', 'category' => 'medical_consumable', 'subcategory' => 'gloves_ppe', 'unit' => 'pair', 'reorder_level' => 200, 'current_stock' => 1000],

            // ===== PHARMACEUTICALS (link to formulary clinical catalog) =====
            ['code' => 'MED-PARA-500-001', 'name' => 'Paracetamol 500 mg', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'tablet', 'reorder_level' => 1000, 'current_stock' => 5000],
            ['code' => 'MED-AMOX-250CAP', 'name' => 'Amoxicillin 250 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'capsule', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-AMPCLOX-500CAP', 'name' => 'Ampicillin + Cloxacillin 500 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'capsule', 'reorder_level' => 300, 'current_stock' => 1000],
            ['code' => 'MED-CEFAD-500CAP', 'name' => 'Cefadroxil 500 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'capsule', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-CEPH-250CAP', 'name' => 'Cephalexin 250 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'capsule', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-DOXY-100CAP', 'name' => 'Doxycycline 100 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'capsule', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-FERR-162CAP', 'name' => 'Ferrotone 162 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'capsule', 'reorder_level' => 300, 'current_stock' => 1000],
            ['code' => 'MED-OMPZ-20CAP', 'name' => 'Omeprazole 20 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'capsule', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-PREG-75CAP', 'name' => 'Pregabalin 75 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'capsule', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-PIROX-20CAP', 'name' => 'Piroxicam 20 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'capsule', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-TRAM-50CAP', 'name' => 'Tramadol 50 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'capsule', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'MED-TAMS-04CAP', 'name' => 'Tamsulosin 0.4 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'urological', 'unit' => 'capsule', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-MISO-200TAB', 'name' => 'Misoprostol 200 mcg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'tablet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-LOPER-5CAP', 'name' => 'Loperamide 5 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'capsule', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-ACICV-10CREAM', 'name' => 'Aciclovir 5% cream 10 g', 'category' => 'pharmaceutical', 'subcategory' => 'antivirals', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-BURN-30CREAM', 'name' => 'Burnox 30 g cream', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-CLTR-15CREAM', 'name' => 'Clotrimazole 1% cream 15 g', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'tube', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-CLOB-10CREAM', 'name' => 'Clobetasol propionate 0.05% cream 10 g', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-DICLO-20GEL', 'name' => 'Diclofenac 1% gel 20 g', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-GENTR-10CREAM', 'name' => 'Gentrisone 10 g cream', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-HYDC-15CREAM', 'name' => 'Hydrocortisone 1% cream 15 g', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-KETO-30CREAM', 'name' => 'Ketoconazole 2% cream 30 g', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-MUPI-10OINT', 'name' => 'Mupirocin 2% ointment 10 g', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-SKDERM-30CREAM', 'name' => 'SK Derm 30 g cream', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-SILVEX-10CREAM', 'name' => 'Silver sulfadiazine + chlorhexidine (Silverex) 10 g', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-TETRA-15OINT', 'name' => 'Tetracycline 1% ointment 15 g', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-WHFL-20OINT', 'name' => "Whitfield's ointment 20 g", 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'tube', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-BPO-20GEL', 'name' => 'Benzoyl peroxide 5% gel (Persone) 20 g', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-BETBZ-30CREAM', 'name' => 'Betamethasone benzoate 0.1% cream 30 g', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 50],
            ['code' => 'MED-CIPRO-EYEDROP', 'name' => 'Ciprofloxacin 0.3% eye drops 10 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-NASAL-ADULT', 'name' => 'Nasal decongestant drops (adult) 15 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-NASAL-PAED', 'name' => 'Nasal decongestant drops (paediatric) 15 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-GENT-EYEDROP', 'name' => 'Gentamicin 0.3% eye drops 10 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-BORIC-EARDROP', 'name' => 'Boric acid ear drops 15 ml', 'category' => 'pharmaceutical', 'subcategory' => 'otological', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-CHLOR-EYEINT', 'name' => 'Chloramphenicol eye ointment', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tube', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-DEXNEO-EYEDROP', 'name' => 'Dexamethasone + Neomycin (Dexaneomycin) eye drops 10 ml', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-DEXP-EYEDROP', 'name' => 'Dexamethasone sodium phosphate eye drops 0.1%', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-CHLOR-EYEDROP', 'name' => 'Chloramphenicol eye drops 0.5% 5 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CIPRO-IV100', 'name' => 'Ciprofloxacin 200 mg/100 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-D5-IV500', 'name' => 'Dextrose 5% 500 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'iv_fluids', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-DNS-IV500', 'name' => 'Dextrose Normal Saline 500 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'iv_fluids', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-FLUC-IV100', 'name' => 'Fluconazole 200 mg/100 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'each', 'reorder_level' => 5, 'current_stock' => 20],
            ['code' => 'MED-METRO-IV100', 'name' => 'Metronidazole 500 mg/100 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-NS-IV500', 'name' => 'Normal Saline 0.9% 500 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'iv_fluids', 'unit' => 'each', 'reorder_level' => 30, 'current_stock' => 150],
            ['code' => 'MED-RL-IV500', 'name' => "Ringer's Lactate 500 ml IV infusion", 'category' => 'pharmaceutical', 'subcategory' => 'iv_fluids', 'unit' => 'each', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-SALB-NEB25', 'name' => 'Salbutamol 2.5 mg nebulisation solution', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'each', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-PARA-IV100', 'name' => 'Paracetamol 1 g/100 ml IV infusion', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'each', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-ADREN-1ML', 'name' => 'Adrenaline (Epinephrine) 1 mg/ml injection 1 ml', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AMINO-250IV', 'name' => 'Aminophylline 250 mg/10 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'ampoule', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-AMPIC-250IV', 'name' => 'Ampicillin 250 mg injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-AMPCLOX-500IV', 'name' => 'Ampicillin + Cloxacillin 500 mg injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-AMOCL-12IV', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 1.2 g injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-ACECL-100TAB', 'name' => 'Aceclofenac 100 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-ACICV-200TAB', 'name' => 'Aciclovir 200 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antivirals', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-ALBEN-200TAB', 'name' => 'Albendazole 400 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'anthelmintics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-ALBEN-10SYR', 'name' => 'Albendazole 400 mg/10 ml syrup', 'category' => 'pharmaceutical', 'subcategory' => 'anthelmintics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-MUCAD-100SYR', 'name' => 'Ambroxol (Mucolyn Adult) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-MUCPA-100SYR', 'name' => 'Ambroxol (Mucolyn Paediatric) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AMINO-100TAB', 'name' => 'Aminophylline 100 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-AMOCL-375TAB', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 375 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-AMOCL-625TAB', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) 625 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-AMOCL-100SYR', 'name' => 'Amoxicillin + Clavulanate (Amox-Clav) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AMOX-100SYR', 'name' => 'Amoxicillin 250 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 30, 'current_stock' => 150],
            ['code' => 'MED-AMPCLX-100SYR', 'name' => 'Ampicillin + Cloxacillin (Ampiclox) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AMPCLXN-06SYR', 'name' => 'Ampicillin + Cloxacillin neonatal syrup 60 mg/ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-ANTAC-100SYR', 'name' => 'Antacid / Relcergel syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'bottle', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-AL-22SYR', 'name' => 'Artemether + Lumefantrine (AL) 22.4 mg/ml syrup', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-LONART-24SYR', 'name' => 'Artemether + Lumefantrine (Lonart DS) 80 mg/480 mg syrup 24 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-ALUME-12TAB', 'name' => 'Artemether + Lumefantrine 20/120 mg (12 tablets)', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'pack', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'MED-ALUME-24TAB', 'name' => 'Artemether + Lumefantrine 20/120 mg (24 tablets)', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'pack', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-ALUME-6TAB', 'name' => 'Artemether + Lumefantrine 80/480 mg (Lonart DS) (6 tablets)', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'pack', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-ARTE-80IM', 'name' => 'Artemether 80 mg/ml injection 1 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'ampoule', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-ARTSN-120IV', 'name' => 'Artesunate 120 mg injection (IV/IM)', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'vial', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-ARTSN-60IV', 'name' => 'Artesunate 60 mg injection (IV/IM)', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'vial', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-ASPJ-75TAB', 'name' => 'Aspirin Junior 75 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-ATEN-50TAB', 'name' => 'Atenolol 50 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-ATROP-1IV', 'name' => 'Atropine sulfate 1 mg/ml injection 1 ml', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AZITH-30SYR', 'name' => 'Azithromycin 200 mg/5 ml syrup 30 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-AZITH-250TAB', 'name' => 'Azithromycin 250 mg (Azuma) tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-AZITH-500TAB', 'name' => 'Azithromycin 500 mg (Azuma) tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-BBE-100LOT', 'name' => 'BB lotion 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-BACL-10TAB', 'name' => 'Baclofen 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-BELLAD-100SYR', 'name' => 'Belladonna syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-BENDFT-5TAB', 'name' => 'Bendroflumethiazide 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-PENAD-24IM', 'name' => 'Benzathine benzylpenicillin (Penadur) 2.4 MU injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-BENZP-5MU', 'name' => 'Benzylpenicillin (Penicillin G) 5 MU injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-BISAC-5TAB', 'name' => 'Bisacodyl 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-CALZ-100LOT', 'name' => 'Calamine + Zinc oxide lotion 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'dermatological', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-CAPT-25TAB', 'name' => 'Captopril 25 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-CEFIX-400TAB', 'name' => 'Cefixime 400 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-CEFOT-12IV', 'name' => 'Cefotaxime 1.2 g injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-CEFTRS-15IV', 'name' => 'Ceftriaxone + Sulbactam 1.5 g injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-CEFTR-1IV', 'name' => 'Ceftriaxone 1 g injection', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'vial', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-CEPH-100SYR', 'name' => 'Cephalexin 250 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CETIR-10TAB', 'name' => 'Cetirizine 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antihistamines', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-CETIR-60SYR', 'name' => 'Cetirizine hydrochloride 10 mg syrup 60 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antihistamines', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CIPT-600TAB', 'name' => 'Ciprofloxacin + Tinidazole 600 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-CIPRO-500TAB', 'name' => 'Ciprofloxacin 500 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-CLARI-500TAB', 'name' => 'Clarithromycin 500 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-CLTR-100PESS', 'name' => 'Clotrimazole 100 mg vaginal pessary', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'pessary', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-COTRI-480TAB', 'name' => 'Co-trimoxazole 480 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-COTRI-100SYR', 'name' => 'Co-trimoxazole 240 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CODRIL-100SYR', 'name' => 'Codril cough syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CMAG-250TAB', 'name' => 'Compound Magnesium Trisilicate 250 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-COUGH-100SYR', 'name' => 'Cough syrup (Prynalyn) 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-DEXAM-4IV', 'name' => 'Dexamethasone sodium phosphate 4 mg injection', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'ampoule', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-DIAZ-10IV', 'name' => 'Diazepam 10 mg/2 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-DIAZ-5TAB', 'name' => 'Diazepam 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-DICLO-3IM', 'name' => 'Diclofenac sodium 75 mg/3 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'ampoule', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-DRCOLD-100SYR', 'name' => 'Dr Cold (Phenylephrine + Chlorphenamine) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-DUOCO-360TAB', 'name' => 'Duo-Cotex 360 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-DUPH-10TAB', 'name' => 'Duphaston (Dydrogesterone) 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'tablet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-ERYTH-250TAB', 'name' => 'Erythromycin stearate 250 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-ERYTH-100SYR', 'name' => 'Erythromycin stearate 250 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-FERSUL-200TAB', 'name' => 'Ferrous sulphate 200 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'tablet', 'reorder_level' => 300, 'current_stock' => 1000],
            ['code' => 'MED-FLUC-150CAP', 'name' => 'Fluconazole 150 mg capsule', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'capsule', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-FOLIC-5TAB', 'name' => 'Folic acid 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-FURO-40TAB', 'name' => 'Furosemide 40 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-FURO-10IV', 'name' => 'Furosemide 10 mg/ml injection 2 ml', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'ampoule', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-GENT-40IM', 'name' => 'Gentamicin 40 mg/ml injection 2 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'ampoule', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-GLOBZ-200SYR', 'name' => 'Globin Z haematinic syrup 200 ml', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-GRIPE-100SYR', 'name' => 'Gripe water 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-GRIS-500TAB', 'name' => 'Griseofulvin 500 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-HEMAT-200SYR', 'name' => 'Hematone haematinic syrup 200 ml', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-HEMOV-200SYR', 'name' => 'Hemovit syrup 200 ml', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-HYDR-25TAB', 'name' => 'Hydralazine 25 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-HYDC-100IV', 'name' => 'Hydrocortisone 100 mg injection', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'vial', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-HYOSC-10TAB', 'name' => 'Hyoscine butylbromide 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-HYOSC-10IV', 'name' => 'Hyoscine butylbromide 20 mg/5 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-IBUP-200TAB', 'name' => 'Ibuprofen 200 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-IBUP-100SYR', 'name' => 'Ibuprofen 100 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-IRONS-20IV', 'name' => 'Iron sucrose 20 mg/ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'ampoule', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-LACT-100SYR', 'name' => 'Lactulose syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-LOPER-2TAB', 'name' => 'Loperamide hydrochloride 2 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-LORA-10TAB', 'name' => 'Loratadine 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antihistamines', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-MALAF-525TAB', 'name' => 'Malafin (Sulfamethoxypyrazine + Pyrimethamine) 525 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antimalarials', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-MEBEN-100TAB', 'name' => 'Mebendazole 100 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'anthelmintics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-MEDRO-150IM', 'name' => 'Medroxyprogesterone acetate 150 mg/ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'vial', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-MELOX-15TAB', 'name' => 'Meloxicam 15 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'anti_inflammatory', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-METGLIM-501TAB', 'name' => 'Metformin + Glimepiride 500 mg/1 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'endocrine', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-METF-500TAB', 'name' => 'Metformin hydrochloride 500 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'endocrine', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-METOC-10TAB', 'name' => 'Metoclopramide hydrochloride 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-METOC-2IV', 'name' => 'Metoclopramide hydrochloride 10 mg/2 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-METMI-200TAB', 'name' => 'Metronidazole + Miconazole vaginal tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'tablet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-METRO-200TAB', 'name' => 'Metronidazole 200 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-METRO-100SYR', 'name' => 'Metronidazole 200 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-MICG-400PESS', 'name' => 'Miconazole (Gynazol) nitrate 400 mg vaginal pessary', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'pessary', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-GYNEX-PESS', 'name' => 'Miconazole + Metronidazole (Gynex) pessary', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'pessary', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-MONT-10TAB', 'name' => 'Montelukast 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-MONT-5TAB', 'name' => 'Montelukast 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'tablet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-MULTV-100SYR', 'name' => 'Multivitamin syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'bottle', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'MED-MULTV-TAB', 'name' => 'Multivitamin tablet', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-MUMFER-150SYR', 'name' => 'Mumfer iron and folic acid syrup 150 ml', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-NEURO-300TAB', 'name' => 'Neurotone (Methylcobalamin) 300 mcg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-NIFE-20TAB', 'name' => 'Nifedipine 20 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'cardiovascular', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-NITF-100TAB', 'name' => 'Nitrofurantoin 100 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-NORE-5TAB', 'name' => 'Norethisterone (NOR 5) 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'tablet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-NYST-30SYR', 'name' => 'Nystatin oral suspension 100,000 IU/ml 30 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antifungals', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-ORS-POW', 'name' => 'ORS rehydration salt sachet', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'sachet', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'MED-GLUC-80POW', 'name' => 'Oral rehydration glucose powder 80 g sachet', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'sachet', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-OXYT-10IU', 'name' => 'Oxytocin 10 IU/ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-PANTO-40IV', 'name' => 'Pantoprazole 40 mg injection', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'vial', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-PANTO-40TAB', 'name' => 'Pantoprazole 40 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-PARA-SUP125', 'name' => 'Paracetamol suppository 125 mg', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'suppository', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-PVPC-250TAB', 'name' => 'Phenoxymethylpenicillin 250 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-PRED-5TAB', 'name' => 'Prednisolone 5 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'tablet', 'reorder_level' => 200, 'current_stock' => 500],
            ['code' => 'MED-PROM-25TAB', 'name' => 'Promethazine 25 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antihistamines', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-PROM-2IM', 'name' => 'Promethazine hydrochloride 25 mg/ml injection 2 ml', 'category' => 'pharmaceutical', 'subcategory' => 'antihistamines', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-SKTONE-100SYR', 'name' => 'Sktonic (Iron, Vitamin B, Folic Acid, Zinc) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-CITAL-100SYR', 'name' => 'Sodium citrate (Cital) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'gastrointestinal', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-TERMID-100SYR', 'name' => 'Termidol (Ibuprofen + Paracetamol) syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-TETAN-05IM', 'name' => 'Tetanus toxoid vaccine 0.5 ml', 'category' => 'pharmaceutical', 'subcategory' => 'vaccines', 'unit' => 'vial', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'MED-TINI-500TAB', 'name' => 'Tinidazole 500 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'antibiotics', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-TIZA-4TAB', 'name' => 'Tizanidine 4 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'neurological', 'unit' => 'tablet', 'reorder_level' => 100, 'current_stock' => 300],
            ['code' => 'MED-TRAM-2IV', 'name' => 'Tramadol hydrochloride 100 mg/2 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'analgesics', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-TRANE-5IV', 'name' => 'Tranexamic acid 500 mg/5 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'haematological', 'unit' => 'ampoule', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-TRIAM-40IM', 'name' => 'Triamcinolone acetonide 40 mg/ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'hormones_contraceptives', 'unit' => 'vial', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'MED-VITBC-10TAB', 'name' => 'Vitamin B complex 10 mg tablet', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'tablet', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'MED-VITB-10IM', 'name' => 'Vitamin B complex 10 ml injection', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'ampoule', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-VITBC-100SYR', 'name' => 'Vitamin B-complex syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-ZECUF-100SYR', 'name' => 'Zecuf herbal cough syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'respiratory', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'MED-ZNSUL-20TAB', 'name' => 'Zinc sulphate 20 mg dispersible tablet', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'tablet', 'reorder_level' => 300, 'current_stock' => 1000],
            ['code' => 'MED-ZNSUL-100SYR', 'name' => 'Zinc sulphate 20 mg/5 ml syrup 100 ml', 'category' => 'pharmaceutical', 'subcategory' => 'nutritional', 'unit' => 'bottle', 'reorder_level' => 20, 'current_stock' => 100],

            // ===== ADDITIONAL LABORATORY SUPPLIES & REAGENTS =====
            ['code' => 'INV-LAB-LANCET-001', 'name' => 'Lancet (sterile)', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-LAB-EDTA-CAP-001', 'name' => 'EDTA capillary tube', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-LAB-VAC-NEEDLE-001', 'name' => 'Vacutainer needle', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 300, 'current_stock' => 1500],
            ['code' => 'INV-LAB-URINE-DIPSTICK-001', 'name' => 'Urine dipstick', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'strip', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-LAB-STOOL-APPLICATOR-001', 'name' => 'Wooden spatula / applicator', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-NS-100-001', 'name' => 'Normal saline 100 ml (for wet mount)', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-LAB-KOH-001', 'name' => 'KOH 10% solution', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'bottle', 'reorder_level' => 5, 'current_stock' => 20],
            ['code' => 'INV-LAB-LIPID-REAGENT-001', 'name' => 'Lipid panel reagent', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-CREAT-REAGENT-001', 'name' => 'Creatinine reagent', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'test', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-LAB-WIDAL-ANTIGEN-001', 'name' => 'Widal antigen suspension', 'category' => 'laboratory', 'subcategory' => 'reagents_kits', 'unit' => 'set', 'reorder_level' => 5, 'current_stock' => 20],
            ['code' => 'INV-LAB-HVS-SWAB-001', 'name' => 'Sterile vaginal swab', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-LAB-SPIRIT-001', 'name' => 'Surgical spirit / alcohol prep', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-LAB-COTTON-001', 'name' => 'Cotton wool swab', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-LAB-POVIDONE-IODINE-001', 'name' => 'Povidone iodine 10% solution', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],

            // ===== ADDITIONAL MEDICAL CONSUMABLES =====
            ['code' => 'INV-MEDCO-GAUZE-001', 'name' => 'Sterile gauze (4x4)', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-MEDCO-COTTON-001', 'name' => 'Cotton wool roll', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'roll', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-TAPE-001', 'name' => 'Adhesive tape / plaster', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'roll', 'reorder_level' => 30, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-BANDAGE-001', 'name' => 'Sterile bandage', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-SCALPEL-11-001', 'name' => 'Scalpel blade #11', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-SCALPEL-15-001', 'name' => 'Scalpel blade #15', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-LIDOCAINE-001', 'name' => 'Lidocaine 1% injection 2 ml', 'category' => 'pharmaceutical', 'subcategory' => 'anaesthetics', 'unit' => 'ampoule', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-SUTURE-SILK-001', 'name' => 'Suture silk 3-0', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-SUTURE-REMOVAL-001', 'name' => 'Suture removal kit', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'set', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-DRAIN-001', 'name' => 'Drain tube / wick', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-NS-FLUSH-001', 'name' => 'Normal saline 10 ml flush syringe', 'category' => 'pharmaceutical', 'subcategory' => 'iv_fluids', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-TOURNIQUET-001', 'name' => 'Tourniquet', 'category' => 'medical_consumable', 'subcategory' => 'iv_therapy', 'unit' => 'piece', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-TRANSPARENT-DRESS-001', 'name' => 'Transparent dressing (for IV cannula)', 'category' => 'medical_consumable', 'subcategory' => 'wound_care', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
            ['code' => 'INV-MEDCO-BLOOD-GIVING-001', 'name' => 'Blood giving set', 'category' => 'medical_consumable', 'subcategory' => 'iv_therapy', 'unit' => 'piece', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-OXYGEN-MASK-001', 'name' => 'Oxygen mask', 'category' => 'medical_consumable', 'subcategory' => 'respiratory', 'unit' => 'piece', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-MEDCO-MVA-SYRINGE-001', 'name' => 'MVA syringe (handheld vacuum)', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 5, 'current_stock' => 20],
            ['code' => 'INV-MEDCO-MVA-CANNULA-001', 'name' => 'MVA cannula (suitable size)', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-MEDCO-SPECULUM-001', 'name' => 'Disposable vaginal speculum', 'category' => 'medical_consumable', 'subcategory' => 'surgical', 'unit' => 'piece', 'reorder_level' => 50, 'current_stock' => 200],
            ['code' => 'INV-MEDCO-IMPLANT-ROD-001', 'name' => 'Contraceptive implant rod (Implanon)', 'category' => 'pharmaceutical', 'subcategory' => 'maternal_health', 'unit' => 'piece', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-NEB-MASK-001', 'name' => 'Nebulisation mask kit', 'category' => 'medical_consumable', 'subcategory' => 'respiratory', 'unit' => 'piece', 'reorder_level' => 20, 'current_stock' => 100],
            ['code' => 'INV-MEDCO-NEEDLE-21G-001', 'name' => 'Hypodermic needle 21G', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-MEDCO-NEEDLE-23G-001', 'name' => 'Hypodermic needle 23G', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 500, 'current_stock' => 2000],
            ['code' => 'INV-MEDCO-NEEDLE-26G-001', 'name' => 'Hypodermic needle 26G', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],
            ['code' => 'INV-MEDCO-SYRINGE-1ML-001', 'name' => 'Syringe 1 ml (tuberculin / insulin)', 'category' => 'medical_consumable', 'subcategory' => 'syringes_needles', 'unit' => 'piece', 'reorder_level' => 200, 'current_stock' => 1000],

            // ===== RADIOLOGY SUPPLIES (stocked as medical_consumable) =====
            ['code' => 'INV-RAD-US-GEL-001', 'name' => 'Ultrasound gel', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'bottle', 'reorder_level' => 10, 'current_stock' => 50],
            ['code' => 'INV-RAD-PROBE-COVER-001', 'name' => 'Ultrasound probe cover', 'category' => 'medical_consumable', 'subcategory' => 'consumables', 'unit' => 'piece', 'reorder_level' => 100, 'current_stock' => 500],
        ];

        $count = 0;

        foreach ($items as $data) {
            $clinicalCatalogItemId = null;
            if ($data['category'] === 'pharmaceutical') {
                $catalog = ClinicalCatalogItemModel::where('facility_id', $facility->id)
                    ->where('catalog_type', 'formulary_item')
                    ->where('code', $data['code'])
                    ->first();
                if (!$catalog) {
                    $this->command?->warn("Skipping {$data['code']} ({$data['name']}) — no formulary catalog item found.");
                    continue;
                }
                $clinicalCatalogItemId = $catalog->id;
            }

            $item = InventoryItemModel::firstOrCreate(
                [
                    'facility_id' => $facility->id,
                    'item_code' => $data['code'],
                ],
                [
                    'tenant_id' => $facility->tenant_id,
                    'clinical_catalog_item_id' => $clinicalCatalogItemId,
                    'default_warehouse_id' => $warehouse?->id,
                    'default_supplier_id' => $supplier?->id,
                    'item_name' => $data['name'],
                    'category' => $data['category'],
                    'subcategory' => $data['subcategory'],
                    'unit' => $data['unit'],
                    'current_stock' => $data['current_stock'] ?? 0,
                    'reorder_level' => $data['reorder_level'] ?? 0,
                    'status' => 'active',
                ],
            );

            InventoryItemUnitModel::firstOrCreate(
                [
                    'item_id' => $item->id,
                    'unit_name' => $data['unit'],
                ],
                [
                    'tenant_id' => $facility->tenant_id,
                    'facility_id' => $facility->id,
                    'base_quantity' => 1,
                    'is_base_unit' => true,
                    'is_default_sales_unit' => true,
                    'is_default_purchase_unit' => true,
                    'is_active' => true,
                ],
            );
            $count++;
        }

        $this->command?->info("Seeded {$count} inventory items for DSK Dispensary.");
    }
}
