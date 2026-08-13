<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private static function resultTemplates(): array
    {
        return [
            'LAB-MRDT-001' => [
                'sections' => [
                    [
                        'label' => 'Test Result',
                        'fields' => [
                            ['code' => 'result', 'label' => 'Result', 'type' => 'positive-negative'],
                        ],
                    ],
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
            'LAB-WIDAL-001' => [
                'sections' => [
                    [
                        'label' => 'Test Result',
                        'fields' => [
                            ['code' => 'result', 'label' => 'Result', 'type' => 'reactive-nonreactive'],
                        ],
                    ],
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
        // No-op: data migration — removing the new section would destroy
        // production data entered against it.
    }
};
