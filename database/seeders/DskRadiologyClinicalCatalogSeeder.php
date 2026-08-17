<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Seeder;

class DskRadiologyClinicalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $facility = FacilityModel::where('code', 'DSK')->first();

        if (!$facility) {
            $this->command?->error('DSK facility not found. Run InitialFacilitySeeder first.');
            return;
        }

        $deptId = DepartmentModel::where('facility_id', $facility->id)->where('code', 'RAD')->value('id');

        if (!$deptId) {
            $this->command?->error('RAD department not found for DSK. Run DskDepartmentsSeeder first.');
            return;
        }

        $items = [
            [
                'code' => 'RAD-US-ABDOMEN',
                'name' => 'Abdominal Ultrasound',
                'category' => 'ultrasound',
                'unit' => 'study',
                'description' => 'An imaging test that uses sound waves to assess abdominal organs and detect abnormalities.',
            ],
            [
                'code' => 'RAD-US-PELVIS',
                'name' => 'Pelvic Ultrasound',
                'category' => 'ultrasound',
                'unit' => 'study',
                'description' => 'An imaging test used to examine the uterus, ovaries, cervix, and other pelvic organs.',
            ],
            [
                'code' => 'RAD-US-OBSTETRIC',
                'name' => 'Obstetric Ultrasound',
                'category' => 'ultrasound',
                'unit' => 'study',
                'description' => 'An imaging test used for fetal viability assessment, gestational age estimation, anomaly screening, and placenta localization during pregnancy.',
            ],
            [
                'code' => 'RAD-US-THYROID',
                'name' => 'Thyroid / Neck Ultrasound',
                'category' => 'ultrasound',
                'unit' => 'study',
                'description' => 'An imaging test to evaluate the thyroid gland, adjacent neck structures, and detect nodules or masses.',
            ],
            [
                'code' => 'RAD-US-SCROTAL',
                'name' => 'Scrotal Ultrasound',
                'category' => 'ultrasound',
                'unit' => 'study',
                'description' => 'An imaging test used to evaluate testicular and scrotal abnormalities including masses, torsion, and hydrocele.',
            ],
        ];

        foreach ($items as $item) {
            ClinicalCatalogItemModel::updateOrCreate(
                [
                    'facility_id' => $facility->id,
                    'catalog_type' => 'radiology_procedure',
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

        $this->command?->info('Seeded ' . count($items) . ' radiology procedure catalog items for DSK Dispensary.');
    }
}
