<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function resultTemplates(): array
    {
        return [
            'LAB-UA-001' => [
                'sections' => [
                    [
                        'label' => 'Physical Examination',
                        'fields' => [
                            ['code' => 'color', 'label' => 'Color', 'type' => 'select', 'options' => ['Pale Yellow', 'Yellow', 'Dark Yellow', 'Amber', 'Red', 'Brown', 'Colourless', 'Cloudy']],
                            ['code' => 'appearance', 'label' => 'Appearance', 'type' => 'select', 'options' => ['Clear', 'Slightly Cloudy', 'Cloudy', 'Turbid']],
                        ],
                    ],
                    [
                        'label' => 'Dipstick',
                        'fields' => [
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
                        ],
                    ],
                    [
                        'label' => 'Microscopy',
                        'fields' => [
                            ['code' => 'wbc', 'label' => 'White Blood Cells', 'type' => 'text', 'placeholder' => 'e.g. 0–5/HPF'],
                            ['code' => 'rbc', 'label' => 'Red Blood Cells', 'type' => 'text', 'placeholder' => 'e.g. 0–3/HPF'],
                            ['code' => 'epithelial_cells', 'label' => 'Epithelial Cells', 'type' => 'text', 'placeholder' => 'e.g. Few, Moderate, Many'],
                            ['code' => 'casts', 'label' => 'Casts', 'type' => 'select', 'options' => ['None Seen', 'Hyaline', 'Granular', 'Cellular', 'Waxy']],
                            ['code' => 'crystals', 'label' => 'Crystals', 'type' => 'select', 'options' => ['None Seen', 'Calcium Oxalate', 'Uric Acid', 'Triple Phosphate', 'Amorphous']],
                            ['code' => 'bacteria', 'label' => 'Bacteria', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate', 'Many']],
                            ['code' => 'yeast', 'label' => 'Yeast Cells', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate']],
                        ],
                    ],
                ],
            ],
            'LAB-STOOL-001' => [
                'sections' => [
                    [
                        'label' => 'Macroscopic Examination',
                        'fields' => [
                            ['code' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => ['Brown', 'Yellow', 'Green', 'Black', 'Red', 'Pale', 'Other']],
                            ['code' => 'consistency', 'label' => 'Consistency', 'type' => 'select', 'options' => ['Formed', 'Soft', 'Loose', 'Watery', 'Mucoid']],
                            ['code' => 'mucus', 'label' => 'Mucus', 'type' => 'not-done'],
                            ['code' => 'blood_visible', 'label' => 'Blood (visible)', 'type' => 'not-done'],
                            ['code' => 'pus', 'label' => 'Pus', 'type' => 'not-done'],
                            ['code' => 'worms_segments', 'label' => 'Worms / Segments', 'type' => 'not-done'],
                            ['code' => 'adult_parasites', 'label' => 'Adult Parasites Seen', 'type' => 'text', 'placeholder' => 'e.g. Ascaris worm…'],
                        ],
                    ],
                    [
                        'label' => 'Microscopic Examination',
                        'fields' => [
                            ['code' => 'rbc', 'label' => 'Red Blood Cells (RBC)', 'type' => 'text', 'placeholder' => 'e.g. 0–2/HPF'],
                            ['code' => 'wbc', 'label' => 'White Blood Cells (WBC/Pus cells)', 'type' => 'text', 'placeholder' => 'e.g. 3–5/HPF'],
                            ['code' => 'epithelial_cells', 'label' => 'Epithelial Cells', 'type' => 'text', 'placeholder' => 'e.g. Few, Moderate, Many'],
                            ['code' => 'yeast_cells', 'label' => 'Yeast Cells', 'type' => 'text', 'placeholder' => 'e.g. None, Few, Moderate'],
                            ['code' => 'fat_globules', 'label' => 'Fat Globules', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                            ['code' => 'starch_granules', 'label' => 'Starch Granules', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                            ['code' => 'muscle_fibres', 'label' => 'Muscle Fibres', 'type' => 'select', 'options' => ['Absent', 'Few', 'Moderate', 'Many']],
                        ],
                    ],
                    [
                        'label' => 'Ova and Parasites',
                        'fields' => [
                            ['code' => 'ova_seen', 'label' => 'Ova Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Ascaris lumbricoides', 'Hookworm', 'Trichuris trichiura', 'Taenia spp.', 'Schistosoma mansoni', 'Hymenolepis nana', 'Other']],
                            ['code' => 'cysts_seen', 'label' => 'Cysts Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Giardia lamblia', 'Entamoeba histolytica', 'Entamoeba coli', 'Other']],
                            ['code' => 'trophozoites_seen', 'label' => 'Trophozoites Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Giardia lamblia', 'Entamoeba histolytica', 'Other']],
                            ['code' => 'larvae_seen', 'label' => 'Larvae Seen', 'type' => 'multiselect', 'options' => ['None Seen', 'Strongyloides stercoralis', 'Other']],
                        ],
                    ],
                    [
                        'label' => 'Occult Blood',
                        'fields' => [
                            ['code' => 'occult_blood', 'label' => 'Occult Blood', 'type' => 'positive-negative'],
                        ],
                    ],
                    [
                        'label' => 'Additional Tests',
                        'fields' => [
                            ['code' => 'ph', 'label' => 'pH', 'type' => 'number', 'placeholder' => 'e.g. 6.5'],
                            ['code' => 'reducing_substance', 'label' => 'Reducing Substance', 'type' => 'positive-negative'],
                        ],
                    ],
                ],
            ],
            'LAB-MPS-001' => [
                'sections' => [
                    [
                        'label' => 'Parasite Identification',
                        'fields' => [
                            ['code' => 'species', 'label' => 'Species', 'type' => 'multiselect', 'options' => ['None Seen', 'Plasmodium falciparum', 'Plasmodium vivax', 'Plasmodium ovale', 'Plasmodium malariae', 'Mixed infection']],
                            ['code' => 'stage', 'label' => 'Stage Seen', 'type' => 'multiselect', 'options' => ['Rings (Trophozoites)', 'Schizonts', 'Gametocytes']],
                            ['code' => 'parasite_density', 'label' => 'Parasite Density', 'type' => 'select', 'options' => ['+ (1-10 parasites / 100 HPF)', '++ (11-100 parasites / 100 HPF)', '+++ (1-10 parasites / HPF)', '++++ (>10 parasites / HPF)']],
                        ],
                    ],
                ],
            ],
            'LAB-HIV-001' => [
                'sections' => [
                    [
                        'label' => 'Test Result',
                        'fields' => [
                            ['code' => 'result', 'label' => 'Result', 'type' => 'select', 'options' => ['Non-Reactive', 'Reactive', 'Invalid']],
                            ['code' => 'kit_name', 'label' => 'Kit Used', 'type' => 'select', 'options' => ['Determine HIV-1/2', 'SD Bioline HIV-1/2', 'Uni-Gold HIV', 'Stat-Pak HIV-1/2', 'Other']],
                            ['code' => 'kit_lot', 'label' => 'Kit Lot Number', 'type' => 'text', 'placeholder' => 'e.g. LOT-12345'],
                        ],
                    ],
                ],
            ],
            'LAB-BGRH-001' => [
                'sections' => [
                    [
                        'label' => 'Blood Group',
                        'fields' => [
                            ['code' => 'abo_group', 'label' => 'ABO Group', 'type' => 'select', 'options' => ['A', 'B', 'AB', 'O']],
                            ['code' => 'rh_type', 'label' => 'Rh Type', 'type' => 'select', 'options' => ['Positive', 'Negative']],
                            ['code' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => ['Slide Method', 'Tube Method', 'Gel Card']],
                        ],
                    ],
                ],
            ],
            'LAB-SPUTUM-001' => [
                'sections' => [
                    [
                        'label' => 'Macroscopic',
                        'fields' => [
                            ['code' => 'colour', 'label' => 'Colour', 'type' => 'select', 'options' => ['Mucoid', 'Purulent', 'Mucopurulent', 'Blood-stained', 'Rusty', 'Frothy']],
                            ['code' => 'consistency', 'label' => 'Consistency', 'type' => 'select', 'options' => ['Thin', 'Thick', 'Viscous']],
                            ['code' => 'saliva', 'label' => 'Saliva (non-representative)', 'type' => 'not-done'],
                        ],
                    ],
                    [
                        'label' => 'Gram Stain',
                        'fields' => [
                            ['code' => 'gram_epi', 'label' => 'Epithelial Cells', 'type' => 'text', 'placeholder' => 'e.g. Few /LPF'],
                            ['code' => 'gram_wbc', 'label' => 'White Blood Cells', 'type' => 'text', 'placeholder' => 'e.g. Many /HPF'],
                            ['code' => 'gram_pos_cocci', 'label' => 'Gram Positive Cocci', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate', 'Many']],
                            ['code' => 'gram_neg_rods', 'label' => 'Gram Negative Rods', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate', 'Many']],
                            ['code' => 'gram_yeast', 'label' => 'Yeast Cells', 'type' => 'select', 'options' => ['None Seen', 'Few', 'Moderate']],
                        ],
                    ],
                    [
                        'label' => 'ZN Stain (AFB)',
                        'fields' => [
                            ['code' => 'afb_result', 'label' => 'AFB Result', 'type' => 'select', 'options' => ['Negative', 'Scanty (1-9 AFB / 100 HPF)', '+ (10-99 AFB / 100 HPF)', '++ (1-10 AFB / HPF)', '+++ (>10 AFB / HPF)']],
                        ],
                    ],
                ],
            ],
            'LAB-WIDAL-001' => [
                'sections' => [
                    [
                        'label' => 'Agglutination Titres',
                        'fields' => [
                            ['code' => 'to_h', 'label' => 'Salmonella Typhi O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                            ['code' => 'th_h', 'label' => 'Salmonella Typhi H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                            ['code' => 'pa_o', 'label' => 'Salmonella Paratyphi A O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                            ['code' => 'pa_h', 'label' => 'Salmonella Paratyphi A H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                            ['code' => 'pb_o', 'label' => 'Salmonella Paratyphi B O', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                            ['code' => 'pb_h', 'label' => 'Salmonella Paratyphi B H', 'type' => 'select', 'options' => ['<1:20', '1:20', '1:40', '1:80', '1:160', '1:320', '1:640']],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        foreach (self::resultTemplates() as $code => $template) {
            $templateJson = json_encode(['resultTemplate' => $template], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $metadataExpr = match ($driver) {
                'pgsql' => "COALESCE(metadata::jsonb, '{}'::jsonb) || '{$templateJson}'::jsonb",
                // SQLite's json1 extension has no JSON_MERGE_PATCH; json_patch() is the
                // RFC 7396 merge-patch equivalent, sufficient for this additive resync.
                'sqlite' => "json_patch(COALESCE(metadata, '{}'), '{$templateJson}')",
                default => "JSON_MERGE_PATCH(COALESCE(metadata, '{}'), '{$templateJson}')",
            };

            DB::table('platform_clinical_catalog_items')
                ->where('catalog_type', 'lab_test')
                ->where('code', $code)
                ->update([
                    'metadata' => DB::raw($metadataExpr),
                ]);
        }
    }

    public function down(): void
    {
        // No-op: this is a one-way data repair. There is no well-defined
        // "previous" state to roll back to — the row being fixed was
        // corrupted, not merely absent (see class docblock).
    }
};
