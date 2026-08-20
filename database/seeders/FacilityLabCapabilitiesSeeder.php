<?php

namespace Database\Seeders;

use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Database\Seeders\Support\FacilitySubscriptionBootstrap;
use Illuminate\Database\Seeder;

/**
 * Data-driven provisioning of facility-specific laboratory capabilities.
 *
 * A single investigation (one `code`, e.g. LAB-URI-ROUTINE) can expose
 * different parameters at different facilities by storing a facility-scoped
 * `resultTemplate` on that facility's own catalog row. This seeder is the
 * authoring point: add a facility to $facilities, choose its urinalysis
 * capability variant, and its catalog is upserted accordingly — no code per
 * facility and no duplicated investigation.
 */
class FacilityLabCapabilitiesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->facilities() as $code => $config) {
            $this->provision($code, $config);
        }
    }

    /**
     * @return array<string, array{name: string, facility_type: string, urinalysis: string}>
     */
    private function facilities(): array
    {
        return [
            // Full laboratory bench: Urinalysis = Physical + Dipstick + Microscopy.
            'DSK' => [
                'name' => 'DSK Dispensary',
                'facility_type' => 'dispensary',
                'urinalysis' => 'full',
            ],
            // Example second facility: Urinalysis = Physical + Dipstick only.
            'DHC' => [
                'name' => 'DHC Health Centre',
                'facility_type' => 'health_centre',
                'urinalysis' => 'dipstick_only',
            ],
        ];
    }

    /**
     * @param  array{name: string, facility_type: string, urinalysis: string}  $config
     */
    private function provision(string $code, array $config): void
    {
        $tenant = TenantModel::firstOrCreate(
            ['code' => $code],
            [
                'name' => $config['name'],
                'country_code' => 'TZ',
                'status' => 'active',
            ],
        );

        $facility = FacilityModel::firstOrCreate(
            ['code' => $code],
            [
                'tenant_id' => $tenant->id,
                'name' => $config['name'],
                'facility_type' => $config['facility_type'],
                'timezone' => 'Africa/Dar_es_Salaam',
                'status' => 'active',
            ],
        );

        FacilitySubscriptionBootstrap::ensureActiveSubscription($tenant->id, $facility->id);

        $labDeptId = DepartmentModel::where('facility_id', $facility->id)
            ->where('code', 'LAB')
            ->value('id');

        if ($labDeptId === null) {
            $labDeptId = DepartmentModel::create([
                'tenant_id' => $tenant->id,
                'facility_id' => $facility->id,
                'code' => 'LAB',
                'name' => 'Laboratory Department',
                'service_type' => 'Diagnostic',
                'description' => 'Performs essential diagnostic investigations at '.$config['name'].'.',
                'is_patient_facing' => true,
                'is_appointmentable' => false,
                'status' => 'active',
            ])->id;
        }

        foreach ($this->baseItems() as $item) {
            $this->upsertItem($tenant->id, $facility->id, $labDeptId, $item, $config['urinalysis']);
        }

        $this->command?->info('Seeded laboratory capabilities for '.$code.' (urinalysis: '.$config['urinalysis'].').');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertItem(
        string $tenantId,
        string $facilityId,
        string $labDeptId,
        array $item,
        string $urinalysisVariant,
    ): void {
        $template = $this->resultTemplateFor($item['code'], $urinalysisVariant);

        $data = [
            'tenant_id' => $tenantId,
            'name' => $item['name'],
            'department_id' => $labDeptId,
            'category' => $item['category'],
            'unit' => $item['unit'],
            'description' => $item['description'],
            'status' => 'active',
        ];

        if ($template !== null) {
            $data['metadata'] = ['resultTemplate' => $template];
        }

        ClinicalCatalogItemModel::updateOrCreate(
            [
                'facility_id' => $facilityId,
                'catalog_type' => 'lab_test',
                'code' => $item['code'],
            ],
            $data,
        );
    }

    /**
     * The shared set of lab investigations offered by every configured facility.
     *
     * @return array<int, array{code: string, name: string, category: string, unit: string, description: string}>
     */
    private function baseItems(): array
    {
        return [
            [
                'code' => 'LAB-URI-ROUTINE',
                'name' => 'Urinalysis (Dipstick + Microscopy)',
                'category' => 'urinalysis',
                'unit' => 'slide',
                'description' => 'Examines urine for infections, kidney disease, and other conditions.',
            ],
            [
                'code' => 'LAB-PAR-MRDT',
                'name' => 'Malaria Rapid Diagnostic Test (mRDT)',
                'category' => 'parasitology',
                'unit' => 'test',
                'description' => 'A rapid screening test that detects malaria parasite antigens in blood.',
            ],
            [
                'code' => 'LAB-SER-HIV-RDT',
                'name' => 'Human Immunodeficiency Virus Test (HIV 1/2)',
                'category' => 'serology_immunology',
                'unit' => 'test',
                'description' => 'Detects HIV infection in a blood sample.',
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
        ];
    }

    /**
     * Return the result template for an investigation at a facility, varying by
     * the facility's capability (e.g. Urinalysis Dipstick-only vs full).
     *
     * @return array<string, mixed>|null
     */
    private function resultTemplateFor(string $code, string $urinalysisVariant): ?array
    {
        if ($code !== 'LAB-URI-ROUTINE') {
            return null;
        }

        $physical = [
            'label' => 'Physical Examination',
            'fields' => [
                ['code' => 'color', 'label' => 'Color', 'type' => 'select', 'options' => ['Pale Yellow', 'Yellow', 'Dark Yellow', 'Amber', 'Red', 'Brown', 'Colourless', 'Cloudy']],
                ['code' => 'appearance', 'label' => 'Appearance', 'type' => 'select', 'options' => ['Clear', 'Slightly Cloudy', 'Cloudy', 'Turbid']],
            ],
        ];

        $dipstick = [
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
        ];

        $microscopy = [
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
        ];

        $sections = $urinalysisVariant === 'dipstick_only'
            ? [$physical, $dipstick]
            : [$physical, $dipstick, $microscopy];

        return ['sections' => $sections];
    }
}
