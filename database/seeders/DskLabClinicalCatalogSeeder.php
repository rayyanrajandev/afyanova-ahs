<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskLabClinicalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $labDeptId = DepartmentModel::where('facility_id', $facility->id)->where('code', 'LAB')->value('id');

        if (!$labDeptId) {
            $this->command?->error('LAB department not found for DSK. Run DskDepartmentsSeeder first.');
            return;
        }

        $items = [
            [
                'code' => 'LAB-PAR-MRDT',
                'name' => 'Malaria Rapid Diagnostic Test (mRDT)',
                'category' => 'parasitology',
                'unit' => 'test',
                'description' => 'A rapid screening test that detects malaria parasite antigens in a small blood sample, providing results within 15–20 minutes.',
            ],
            [
                'code' => 'LAB-SER-HIV-RDT',
                'name' => 'Human Immunodeficiency Virus Test (HIV 1/2)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'A laboratory test used to detect HIV infection in a person\'s blood sample.',
            ],
            [
                'code' => 'LAB-SER-HPYLORI-RDT',
                'name' => 'H. pylori Antibody Test',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'An H. pylori test is used to detect infection with Helicobacter Pylori infection.',
            ],
            [
                'code' => 'LAB-SER-SYPHILIS-RPR',
                'name' => 'Syphilis Test (VDRL / RPR)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'Screens for syphilis infection.',
            ],
            [
                'code' => 'LAB-HEM-HB',
                'name' => 'Hemoglobin (Hb) Test',
                'category' => 'hematology',
                'unit' => 'report',
                'description' => 'Measures blood hemoglobin levels to check for anemia.',
            ],
            [
                'code' => 'LAB-BIO-GLUCOSE-RBG',
                'name' => 'Blood Sugar (RBG)',
                'category' => 'clinical_chemistry',
                'unit' => 'report',
                'description' => 'Measures glucose levels for diabetes screening.',
            ],
            [
                'code' => 'LAB-BB-ABO-RH',
                'name' => 'Blood Grouping & Rh Factor',
                'category' => 'blood_bank_transfusion',
                'unit' => 'test',
                'description' => 'Determines blood type and Rh status.',
            ],
            [
                'code' => 'LAB-URI-ROUTINE',
                'name' => 'Urinalysis (Dipstick + Microscopy)',
                'category' => 'urinalysis',
                'unit' => 'slide',
                'description' => 'Examines urine for infections, kidney disease, and other conditions.',
            ],
            [
                'code' => 'LAB-PAR-STOOL-ROUTINE',
                'name' => 'Stool Analysis (Routine Microscopy)',
                'category' => 'parasitology',
                'unit' => 'slide',
                'description' => 'Detects intestinal parasites and gastrointestinal infections.',
            ],
            [
                'code' => 'LAB-HEM-ESR',
                'name' => 'Erythrocyte Sedimentation Rate (ESR)',
                'category' => 'hematology',
                'unit' => 'sample',
                'description' => 'Detects inflammation in the body.',
            ],
            [
                'code' => 'LAB-MIC-HVS',
                'name' => 'High Vaginal Swab Test (HVS)',
                'category' => 'microbiology',
                'unit' => 'slide',
                'description' => 'Detect Bacteria, Fungi and Parasites.',
            ],
            [
                'code' => 'LAB-SER-UPT',
                'name' => 'Urine Pregnancy Test (UPT)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'Detects pregnancy.',
            ],
            [
                'code' => 'LAB-SER-HBSAG-RDT',
                'name' => 'Hepatitis B Surface Antigen Test (HBsAg)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'Detects Hepatitis B infection.',
            ],
            [
                'code' => 'LAB-SER-WIDAL',
                'name' => 'Typhoid Test (Widal Agglutination)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'Screens for typhoid fever.',
            ],
            [
                'code' => 'LAB-BIO-LIPID-CHO',
                'name' => 'Lipid Profile (Total Cholesterol)',
                'category' => 'clinical_chemistry',
                'unit' => 'report',
                'description' => 'Measures cholesterol levels in the blood.',
            ],
            [
                'code' => 'LAB-BIO-RENAL-URIC',
                'name' => 'Renal Function Test (Uric Acid)',
                'category' => 'clinical_chemistry',
                'unit' => 'report',
                'description' => 'Detects Uric Acid level in blood.',
            ],
            [
                'code' => 'LAB-SER-HCV-RDT',
                'name' => 'Hepatitis C Antibody Test (Anti-HCV)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'A rapid screening test to detect antibodies against Hepatitis C virus in a blood sample.',
            ],
        ];

        foreach ($items as $item) {
            $data = [
                'tenant_id' => $facility->tenant_id,
                'name' => $item['name'],
                'department_id' => $labDeptId,
                'category' => $item['category'],
                'unit' => $item['unit'],
                'description' => $item['description'],
                'status' => 'active',
            ];

            $template = self::resultTemplateForCode($item['code']);
            if ($template !== null) {
                $data['metadata'] = ['resultTemplate' => $template];
            }

            ClinicalCatalogItemModel::updateOrCreate(
                [
                    'facility_id' => $facility->id,
                    'catalog_type' => 'lab_test',
                    'code' => $item['code'],
                ],
                $data,
            );
        }

        $this->command?->info('Seeded ' . count($items) . ' lab test clinical catalog items for DSK Dispensary.');
    }

    private static function resultTemplateForCode(string $code): ?array
    {
        return match ($code) {
            'LAB-URI-ROUTINE' => ['sections' => [
                ['label' => 'Physical Examination', 'fields' => [
                    ['code' => 'color', 'label' => 'Color', 'type' => 'select', 'options' => ['Pale Yellow', 'Yellow', 'Dark Yellow', 'Amber', 'Red', 'Brown', 'Colourless', 'Cloudy']],
                    ['code' => 'appearance', 'label' => 'Appearance', 'type' => 'select', 'options' => ['Clear', 'Slightly Cloudy', 'Cloudy', 'Turbid']],
                ]],
                ['label' => 'Dipstick', 'fields' => [
                    ['code' => 'specific_gravity', 'label' => 'Specific Gravity', 'type' => 'text', 'placeholder' => 'e.g. 1.015'],
                    ['code' => 'ph', 'label' => 'pH', 'type' => 'number', 'placeholder' => 'e.g. 6.0'],
                    ['code' => 'protein', 'label' => 'Protein', 'type' => 'select', 'options' => ['Negative', 'Trace', '+', '++', '+++']],
                    ['code' => 'glucose', 'label' => 'Glucose', 'type' => 'select', 'options' => ['Negative', 'Trace', '+', '++', '+++']],
                    ['code' => 'ketones', 'label' => 'Ketones', 'type' => 'select', 'options' => ['Negative', 'Trace', '+', '++', '+++']],
                    ['code' => 'bilirubin', 'label' => 'Bilirubin', 'type' => 'select', 'options' => ['Negative', '+', '++', '+++']],
                    ['code' => 'urobilinogen', 'label' => 'Urobilinogen', 'type' => 'select', 'options' => ['Normal', '+', '++', '+++']],
                    ['code' => 'nitrites', 'label' => 'Nitrites', 'type' => 'positive-negative'],
                    ['code' => 'blood', 'label' => 'Blood', 'type' => 'select', 'options' => ['Negative', 'Trace', '+', '++', '+++']],
                    ['code' => 'leukocytes', 'label' => 'Leukocytes', 'type' => 'select', 'options' => ['Negative', 'Trace', '+', '++', '+++']],
                ]],
                ['label' => 'Microscopy', 'fields' => [
                    ['code' => 'wbc', 'label' => 'White Blood Cells', 'type' => 'text', 'placeholder' => 'e.g. 0–5/HPF'],
                    ['code' => 'rbc', 'label' => 'Red Blood Cells', 'type' => 'text', 'placeholder' => 'e.g. 0–3/HPF'],
                    ['code' => 'epithelial_cells', 'label' => 'Epithelial Cells', 'type' => 'text', 'placeholder' => 'e.g. Few, Moderate, Many'],
                    ['code' => 'casts', 'label' => 'Casts', 'type' => 'select', 'options' => ['None Seen', 'Hyaline', 'Granular', 'Cellular', 'Waxy']],
                    ['code' => 'crystals', 'label' => 'Crystals', 'type' => 'select', 'options' => ['None Seen', 'Calcium Oxalate', 'Uric Acid', 'Triple Phosphate', 'Amorphous']],
                    ['code' => 'bacteria', 'label' => 'Bacteria', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate', 'Many']],
                    ['code' => 'yeast', 'label' => 'Yeast Cells', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate']],
                    ['code' => 'parasites', 'label' => 'Parasites (e.g. S. haematobium)', 'type' => 'select', 'options' => ['None Seen', 'Schistosoma haematobium ova seen', 'Trichomonas vaginalis seen', 'Other']],
                ]],
            ]],
            'LAB-PAR-STOOL-ROUTINE' => ['sections' => [
                ['label' => 'Macroscopic Examination', 'fields' => [
                    ['code' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => ['Brown', 'Yellow', 'Green', 'Black', 'Red', 'Pale', 'Other']],
                    ['code' => 'consistency', 'label' => 'Consistency', 'type' => 'select', 'options' => ['Formed', 'Soft', 'Loose', 'Watery', 'Mucoid']],
                    ['code' => 'mucus', 'label' => 'Mucus', 'type' => 'not-done'],
                    ['code' => 'blood_visible', 'label' => 'Blood (visible)', 'type' => 'not-done'],
                    ['code' => 'pus', 'label' => 'Pus', 'type' => 'not-done'],
                    ['code' => 'worms_segments', 'label' => 'Worms / Segments', 'type' => 'not-done'],
                    ['code' => 'adult_parasites', 'label' => 'Adult Parasites Seen', 'type' => 'text', 'placeholder' => 'e.g. Ascaris worm…'],
                ]],
                ['label' => 'Microscopic Examination', 'fields' => [
                    ['code' => 'rbc', 'label' => 'Red Blood Cells (RBC)', 'type' => 'text', 'placeholder' => 'e.g. 0–2/HPF'],
                    ['code' => 'wbc', 'label' => 'White Blood Cells (WBC/Pus cells)', 'type' => 'text', 'placeholder' => 'e.g. 3–5/HPF'],
                    ['code' => 'epithelial_cells', 'label' => 'Epithelial Cells', 'type' => 'text', 'placeholder' => 'e.g. Few, Moderate, Many'],
                    ['code' => 'yeast_cells', 'label' => 'Yeast Cells', 'type' => 'text', 'placeholder' => 'e.g. None, Few, Moderate'],
                    ['code' => 'fat_globules', 'label' => 'Fat Globules', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                    ['code' => 'starch_granules', 'label' => 'Starch Granules', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                    ['code' => 'muscle_fibres', 'label' => 'Muscle Fibres', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                ]],
                ['label' => 'Ova and Parasites', 'fields' => [
                    ['code' => 'ova_seen', 'label' => 'Ova Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Ascaris lumbricoides', 'Hookworm', 'Trichuris trichiura', 'Taenia spp.', 'Schistosoma mansoni', 'Hymenolepis nana', 'Other']],
                    ['code' => 'cysts_seen', 'label' => 'Cysts Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Giardia lamblia', 'Entamoeba histolytica', 'Entamoeba coli', 'Other']],
                    ['code' => 'trophozoites_seen', 'label' => 'Trophozoites Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Giardia lamblia', 'Entamoeba histolytica', 'Other']],
                    ['code' => 'larvae_seen', 'label' => 'Larvae Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Strongyloides stercoralis', 'Other']],
                ]],
                ['label' => 'Occult Blood', 'fields' => [
                    ['code' => 'occult_blood', 'label' => 'Occult Blood', 'type' => 'positive-negative'],
                ]],
                ['label' => 'Additional Tests', 'fields' => [
                    ['code' => 'ph', 'label' => 'pH', 'type' => 'number', 'placeholder' => 'e.g. 6.5'],
                    ['code' => 'reducing_substance', 'label' => 'Reducing Substance', 'type' => 'positive-negative'],
                ]],
            ]],
            'LAB-PAR-MRDT' => ['sections' => [
                ['label' => 'Test Result', 'fields' => [
                    ['code' => 'result', 'label' => 'Result', 'type' => 'positive-negative'],
                ]],
                ['label' => 'Parasite Identification', 'fields' => [
                    ['code' => 'species', 'label' => 'Species', 'type' => 'multiselect', 'options' => ['None Seen', 'Plasmodium falciparum', 'Plasmodium vivax', 'Plasmodium ovale', 'Plasmodium malariae', 'Mixed infection']],
                    ['code' => 'stage', 'label' => 'Stage Seen', 'type' => 'multiselect', 'options' => ['Rings (Trophozoites)', 'Schizonts', 'Gametocytes']],
                    ['code' => 'parasite_density', 'label' => 'Parasite Density', 'type' => 'select', 'options' => ['+ (1-10 parasites / 100 HPF)', '++ (11-100 parasites / 100 HPF)', '+++ (1-10 parasites / HPF)', '++++ (>10 parasites / HPF)']],
                ]],
            ]],
            'LAB-SER-HIV-RDT' => ['sections' => [
                ['label' => 'Test Result', 'fields' => [
                    ['code' => 'result', 'label' => 'Result', 'type' => 'select', 'options' => ['Non-Reactive', 'Reactive', 'Invalid']],
                    ['code' => 'kit_name', 'label' => 'Kit Used', 'type' => 'select', 'options' => ['Determine HIV-1/2', 'SD Bioline HIV-1/2', 'Uni-Gold HIV', 'Stat-Pak HIV-1/2', 'Other']],
                    ['code' => 'kit_lot', 'label' => 'Kit Lot Number', 'type' => 'text', 'placeholder' => 'e.g. LOT-12345'],
                ]],
            ]],
            'LAB-BB-ABO-RH' => ['sections' => [
                ['label' => 'Blood Group', 'fields' => [
                    ['code' => 'abo_group', 'label' => 'ABO Group', 'type' => 'select', 'options' => ['A', 'B', 'AB', 'O']],
                    ['code' => 'rh_type', 'label' => 'Rh Type', 'type' => 'select', 'options' => ['Positive', 'Negative']],
                    ['code' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => ['Slide Method', 'Tube Method', 'Gel Card']],
                ]],
            ]],
            'LAB-SER-WIDAL' => ['sections' => [
                ['label' => 'Test Result', 'fields' => [
                    ['code' => 'result', 'label' => 'Result', 'type' => 'reactive-nonreactive'],
                ]],
                ['label' => 'Agglutination Titres', 'fields' => [
                    ['code' => 'to_h', 'label' => 'Salmonella Typhi O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                    ['code' => 'th_h', 'label' => 'Salmonella Typhi H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                    ['code' => 'pa_o', 'label' => 'Salmonella Paratyphi A O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                    ['code' => 'pa_h', 'label' => 'Salmonella Paratyphi A H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                    ['code' => 'pb_o', 'label' => 'Salmonella Paratyphi B O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                    ['code' => 'pb_h', 'label' => 'Salmonella Paratyphi B H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                ]],
            ]],
            default => null,
        };
    }
}
