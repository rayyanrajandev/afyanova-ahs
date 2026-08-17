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
            ['code' => 'PROC-NURS-WOUND-CLEAN', 'name' => 'Wound cleaning (toilet of wound)', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Cleansing of wound with antiseptic solution to remove debris and exudate.'],
            ['code' => 'PROC-NURS-WOUND-DRESS', 'name' => 'Wound dressing', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Application of sterile gauze and bandage over a clean wound.'],
            ['code' => 'PROC-NURS-BURN-DRESS', 'name' => 'Burn dressing', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Dressing of burn wound with topical antimicrobial and sterile cover.'],
            ['code' => 'PROC-SURG-SUTURE-MINOR', 'name' => 'Suturing of simple lacerations', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Primary closure of superficial laceration with nylon or silk suture.'],
            ['code' => 'PROC-SURG-SUTURE-REMOVE', 'name' => 'Suture removal', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of surgical sutures after wound healing is adequate.'],
            ['code' => 'PROC-SURG-INC-DRAIN', 'name' => 'Incision and drainage of abscess', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Incision of skin over abscess, drainage of purulent material, and irrigation.'],
            ['code' => 'PROC-SURG-PARONYCHIA', 'name' => 'Incision and drainage of paronychia', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Drainage of pus from nail fold infection.'],
            ['code' => 'PROC-SURG-FB-REMOVAL', 'name' => 'Removal of superficial foreign bodies', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of splinter, glass, or metal from superficial soft tissue.'],
            ['code' => 'PROC-SURG-WOUND-DEBRIDE', 'name' => 'Minor wound debridement', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of devitalised tissue and foreign matter from a wound.'],
            ['code' => 'PROC-NURS-INJECT-IM', 'name' => 'Intramuscular (IM) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication into the deltoid or gluteal muscle.'],
            ['code' => 'PROC-NURS-INJECT-IV', 'name' => 'Intravenous (IV) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication directly into a vein via a cannula.'],
            ['code' => 'PROC-NURS-INJECT-SC', 'name' => 'Subcutaneous (SC) injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of medication into subcutaneous tissue (e.g., insulin, heparin).'],
            ['code' => 'PROC-NURS-INJECT-ID', 'name' => 'Intradermal injection', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Injection into the dermis, commonly for tuberculin skin testing.'],
            ['code' => 'PROC-LINE-IV-CANNULA', 'name' => 'IV cannulation', 'category' => 'line', 'unit' => 'procedure', 'description' => 'Insertion of peripheral intravenous cannula for access.'],
            ['code' => 'PROC-LINE-IV-FLUID', 'name' => 'IV fluid administration', 'category' => 'line', 'unit' => 'procedure', 'description' => 'Administration of intravenous fluids (e.g., normal saline, Ringer\'s lactate).'],
            ['code' => 'PROC-NURS-TRANSFUSION-MONITOR', 'name' => 'Blood transfusion monitoring (only where available)', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Monitoring of vital signs during and after blood product transfusion.'],
            ['code' => 'PROC-EMERG-MED-ADMIN', 'name' => 'Emergency medication administration', 'category' => 'emergency', 'unit' => 'procedure', 'description' => 'Rapid administration of life-saving medications in an emergency setting.'],
            ['code' => 'PROC-OBG-MVA', 'name' => 'Manual vacuum aspiration (MVA)', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Uterine evacuation using handheld vacuum syringe for incomplete abortion or retained products.'],
            ['code' => 'PROC-OBG-IMPLANT-INSERT', 'name' => 'Implant insertion', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Subdermal contraceptive implant insertion (e.g., Implanon, Jadelle).'],
            ['code' => 'PROC-OBG-IMPLANT-REMOVE', 'name' => 'Implant removal', 'category' => 'minor', 'unit' => 'procedure', 'description' => 'Removal of subdermal contraceptive implant.'],
            ['code' => 'PROC-NURS-HTN-FOLLOWUP', 'name' => 'Hypertension assessment and follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Blood pressure check, medication adherence review, and lifestyle counselling.'],
            ['code' => 'PROC-NURS-DM-FOLLOWUP', 'name' => 'Diabetes follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Blood glucose monitoring, medication adjustment, and dietary advice.'],
            ['code' => 'PROC-RESP-NEBULIZE', 'name' => 'Asthma nebulization and follow-up', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Administration of nebulised bronchodilator and monitoring of response.'],
            ['code' => 'PROC-EMERG-STABILIZE', 'name' => 'Patient stabilization', 'category' => 'emergency', 'unit' => 'procedure', 'description' => 'Initial assessment and stabilisation of an acutely ill or injured patient.'],
            ['code' => 'PROC-NURS-REFERRAL-DOC', 'name' => 'Referral documentation', 'category' => 'nursing_procedure', 'unit' => 'procedure', 'description' => 'Preparation of referral notes and documentation for transfer to higher level facility.'],
        ];

        foreach ($items as $item) {
            ClinicalCatalogItemModel::updateOrCreate(
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
