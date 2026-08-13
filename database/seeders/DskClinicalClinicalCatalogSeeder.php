<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskClinicalClinicalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $deptId = DepartmentModel::where('facility_id', $facility->id)->where('code', 'OPD')->value('id');

        if (!$deptId) {
            $this->command?->error('OPD department not found for DSK. Run DskDepartmentsSeeder first.');
            return;
        }

        $items = [
            ['code' => 'CLN-WOUND-CLEAN-001', 'name' => 'Wound cleaning (toilet of wound)', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Cleansing of wound with antiseptic solution to remove debris and exudate.'],
            ['code' => 'CLN-WOUND-DRESS-001', 'name' => 'Wound dressing', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Application of sterile gauze and bandage over a clean wound.'],
            ['code' => 'CLN-BURN-DRESS-001', 'name' => 'Burn dressing', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Dressing of burn wound with topical antimicrobial and sterile cover.'],
            ['code' => 'CLN-SUTURE-001', 'name' => 'Suturing of simple lacerations', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Primary closure of superficial laceration with nylon or silk suture.'],
            ['code' => 'CLN-SUTURE-REMOVE-001', 'name' => 'Suture removal', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of surgical sutures after wound healing is adequate.'],
            ['code' => 'CLN-INC-DRAIN-001', 'name' => 'Incision and drainage of abscess', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Incision of skin over abscess, drainage of purulent material, and irrigation.'],
            ['code' => 'CLN-PARONYCHIA-001', 'name' => 'Incision and drainage of paronychia', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Drainage of pus from nail fold infection.'],
            ['code' => 'CLN-FB-REMOVAL-001', 'name' => 'Removal of superficial foreign bodies', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of splinter, glass, or metal from superficial soft tissue.'],
            ['code' => 'CLN-WOUND-DEBRIDE-001', 'name' => 'Minor wound debridement', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of devitalised tissue and foreign matter from a wound.'],
            ['code' => 'CLN-INJECTION-IM-001', 'name' => 'Intramuscular (IM) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication into the deltoid or gluteal muscle.'],
            ['code' => 'CLN-INJECTION-IV-001', 'name' => 'Intravenous (IV) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication directly into a vein via a cannula.'],
            ['code' => 'CLN-INJECTION-SC-001', 'name' => 'Subcutaneous (SC) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication into subcutaneous tissue (e.g., insulin, heparin).'],
            ['code' => 'CLN-INJECTION-ID-001', 'name' => 'Intradermal injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Injection into the dermis, commonly for tuberculin skin testing.'],
            ['code' => 'CLN-IV-CANNULA-001', 'name' => 'IV cannulation', 'category' => 'line', 'unit' => 'procedure', 'description' => 'Insertion of peripheral intravenous cannula for access.'],
            ['code' => 'CLN-IV-FLUID-001', 'name' => 'IV fluid administration', 'category' => 'line', 'unit' => 'procedure', 'description' => 'Administration of intravenous fluids (e.g., normal saline, Ringer\'s lactate).'],
            ['code' => 'CLN-BLOOD-TRANSFUSION-001', 'name' => 'Blood transfusion monitoring (only where available)', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Monitoring of vital signs during and after blood product transfusion.'],
            ['code' => 'CLN-EMERG-MED-001', 'name' => 'Emergency medication administration', 'category' => 'emergency', 'unit' => 'procedure', 'description' => 'Rapid administration of life-saving medications in an emergency setting.'],
            ['code' => 'CLN-MVA-001', 'name' => 'Manual vacuum aspiration (MVA)', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Uterine evacuation using handheld vacuum syringe for incomplete abortion or retained products.'],
            ['code' => 'CLN-IMPLANT-INSERT-001', 'name' => 'Implant insertion', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Subdermal contraceptive implant insertion (e.g., Implanon, Jadelle).'],
            ['code' => 'CLN-IMPLANT-REMOVE-001', 'name' => 'Implant removal', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of subdermal contraceptive implant.'],
            ['code' => 'CLN-HTN-FOLLOWUP-001', 'name' => 'Hypertension assessment and follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Blood pressure check, medication adherence review, and lifestyle counselling.'],
            ['code' => 'CLN-DM-FOLLOWUP-001', 'name' => 'Diabetes follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Blood glucose monitoring, medication adjustment, and dietary advice.'],
            ['code' => 'CLN-ASTHMA-NEB-001', 'name' => 'Asthma nebulization and follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of nebulised bronchodilator and monitoring of response.'],
            ['code' => 'CLN-PATIENT-STABILIZE-001', 'name' => 'Patient stabilization', 'category' => 'emergency', 'unit' => 'procedure', 'description' => 'Initial assessment and stabilisation of an acutely ill or injured patient.'],
            ['code' => 'CLN-REFERRAL-001', 'name' => 'Referral documentation', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Preparation of referral notes and documentation for transfer to higher level facility.'],
        ];

        foreach ($items as $item) {
            ClinicalCatalogItemModel::firstOrCreate(
                [
                    'facility_id' => $facility->id,
                    'catalog_type' => 'clinical_procedure',
                    'code' => $item['code'],
                ],
                [
                    'tenant_id' => $facility->tenant_id,
                    'name' => $item['name'],
                    'department_id' => $deptId,
                    'category' => $item['category'],
                    'unit' => $item['unit'],
                    'description' => $item['description'],
                    'status' => 'active',
                ],
            );
        }

        $this->command?->info('Seeded ' . count($items) . ' clinical procedure catalog items for DSK Dispensary.');
    }
}
