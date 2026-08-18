<?php

/**
 * The laboratory worklist is a query, not the first page filtered in a browser.
 *
 * It fetched `?perPage=50` once and did status, discipline and priority filtering
 * client-side, counting the tabs from those same 50 rows. A lab with more than
 * 50 open orders lost the rest silently, and every count described the page
 * rather than the worklist. Its own status-counts endpoint already existed and
 * was called zero times.
 */

use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrderStatusCountsUseCase;
use App\Modules\Laboratory\Application\UseCases\ListLaboratoryOrdersUseCase;
use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function worklistPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PTWL'.strtoupper(Str::random(8)),
        'first_name' => 'Work',
        'last_name' => 'List',
        'gender' => 'male',
        'date_of_birth' => '1988-02-02',
        'phone' => '+2557'.random_int(10000000, 99999999),
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function worklistCatalogItem(string $category): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'lab_test',
        'code' => 'LABWL-'.strtoupper(Str::random(6)),
        'name' => ucfirst($category).' Test',
        'category' => $category,
        'status' => 'active',
    ]);
}

function worklistOrder(string $patientId, array $overrides = []): LaboratoryOrderModel
{
    return LaboratoryOrderModel::query()->create(array_merge([
        'order_number' => 'LABWL'.strtoupper(Str::random(8)),
        'patient_id' => $patientId,
        'ordered_at' => now(),
        'test_code' => 'LOINC:57021-8',
        'test_name' => 'Complete Blood Count',
        'priority' => 'routine',
        'status' => 'ordered',
        'entry_state' => 'active',
    ], $overrides));
}

/**
 * @return array<int, string>
 */
function worklistIds(array $filters): array
{
    $result = app(ListLaboratoryOrdersUseCase::class)->execute($filters + ['perPage' => 100]);

    return array_map(static fn (array $row): string => (string) $row['id'], $result['data']);
}

it('filters by discipline using the catalog category, not a guess from the code', function (): void {
    $patient = worklistPatient();
    $haem = worklistOrder($patient->id, ['lab_test_catalog_item_id' => worklistCatalogItem('hematology')->id]);
    $chem = worklistOrder($patient->id, ['lab_test_catalog_item_id' => worklistCatalogItem('clinical_chemistry')->id]);

    expect(worklistIds(['department' => 'hematology']))->toContain($haem->id);
    expect(worklistIds(['department' => 'hematology']))->not->toContain($chem->id);
    expect(worklistIds(['department' => 'clinical_chemistry']))->toContain($chem->id);
});

it('treats an absent or "all" discipline as no filter', function (): void {
    $patient = worklistPatient();
    worklistOrder($patient->id, ['lab_test_catalog_item_id' => worklistCatalogItem('hematology')->id]);
    worklistOrder($patient->id, ['lab_test_catalog_item_id' => worklistCatalogItem('parasitology')->id]);

    expect(worklistIds([]))->toHaveCount(2);
    expect(worklistIds(['department' => 'all']))->toHaveCount(2);
});

it('filters by status and priority server-side', function (): void {
    $patient = worklistPatient();
    $stat = worklistOrder($patient->id, ['priority' => 'stat', 'status' => 'in_progress']);
    worklistOrder($patient->id, ['priority' => 'routine', 'status' => 'ordered']);

    expect(worklistIds(['priority' => 'stat']))->toBe([$stat->id]);
    expect(worklistIds(['status' => 'in_progress']))->toBe([$stat->id]);
});

it('counts the whole worklist, not the page on screen', function (): void {
    $patient = worklistPatient();
    // More than the 50 the browser used to fetch and then count.
    for ($i = 0; $i < 55; $i++) {
        worklistOrder($patient->id, ['status' => 'ordered']);
    }
    worklistOrder($patient->id, ['status' => 'in_progress']);

    $counts = app(ListLaboratoryOrderStatusCountsUseCase::class)->execute([]);

    expect($counts['ordered'])->toBe(55);
    expect($counts['in_progress'])->toBe(1);
});

it('pages the worklist instead of truncating it', function (): void {
    $patient = worklistPatient();
    for ($i = 0; $i < 55; $i++) {
        worklistOrder($patient->id);
    }

    $first = app(ListLaboratoryOrdersUseCase::class)->execute(['perPage' => 50, 'page' => 1]);
    $second = app(ListLaboratoryOrdersUseCase::class)->execute(['perPage' => 50, 'page' => 2]);

    expect($first['data'])->toHaveCount(50);
    expect($second['data'])->toHaveCount(5);
});

it('counts per discipline as well', function (): void {
    $patient = worklistPatient();
    $haemId = worklistCatalogItem('hematology')->id;
    worklistOrder($patient->id, ['lab_test_catalog_item_id' => $haemId, 'status' => 'ordered']);
    worklistOrder($patient->id, ['lab_test_catalog_item_id' => $haemId, 'status' => 'ordered']);
    worklistOrder($patient->id, ['lab_test_catalog_item_id' => worklistCatalogItem('urinalysis')->id, 'status' => 'ordered']);

    $counts = app(ListLaboratoryOrderStatusCountsUseCase::class)->execute(['department' => 'hematology']);

    expect($counts['ordered'])->toBe(2);
});
