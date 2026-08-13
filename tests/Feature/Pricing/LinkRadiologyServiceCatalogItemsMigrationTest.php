<?php

use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeRadiologyLinkTenant(): TenantModel
{
    return TenantModel::query()->create([
        'code' => 'TRAD'.strtoupper(Str::random(6)),
        'name' => 'Radiology Link Fixture Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeRadiologyLinkFacility(string $tenantId): FacilityModel
{
    return FacilityModel::query()->create([
        'tenant_id' => $tenantId,
        'code' => 'FRAD'.strtoupper(Str::random(6)),
        'name' => 'Radiology Link Fixture Facility',
        'facility_type' => 'hospital',
        'status' => 'active',
    ]);
}

/**
 * RefreshDatabase already runs every migration (including
 * 2026_07_24_000013) once to build the schema, before this test's fixtures
 * exist. To test its actual up() logic, require the file directly and
 * invoke it again against fixtures representing the "before" state --
 * the same technique used for any migration whose value is in its data
 * transformation, not its schema change.
 */
function runRadiologyLinkMigration(): void
{
    $migration = require database_path('migrations/2026_07_24_000013_link_radiology_service_catalog_items_to_clinical_catalog.php');
    $migration->up();
}

function makeUnlinkedRadiologyTariff(?string $tenantId, ?string $facilityId, string $serviceCode): BillingServiceCatalogItemModel
{
    return BillingServiceCatalogItemModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'service_code' => $serviceCode,
        'service_name' => 'Test Radiology Tariff',
        'service_type' => 'radiology',
        'unit' => 'study',
        'base_price' => 50000,
        'currency_code' => 'TZS',
        'status' => 'active',
        'clinical_catalog_item_id' => null,
    ]);
}

function makeRadiologyCatalogItem(?string $tenantId, ?string $facilityId, string $code): ClinicalCatalogItemModel
{
    return ClinicalCatalogItemModel::query()->create([
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'catalog_type' => 'radiology_procedure',
        'code' => $code,
        'name' => 'Test Radiology Procedure',
        'unit' => 'study',
        'status' => 'active',
    ]);
}

it('links an unlinked radiology tariff to the matching clinical catalog item by exact code and scope', function (): void {
    $tenant = makeRadiologyLinkTenant();
    $facility = makeRadiologyLinkFacility($tenant->id);

    $catalogItem = makeRadiologyCatalogItem($tenant->id, $facility->id, 'RAD-TEST-001');
    $tariff = makeUnlinkedRadiologyTariff($tenant->id, $facility->id, 'RAD-TEST-001');

    runRadiologyLinkMigration();

    expect($tariff->fresh()->clinical_catalog_item_id)->toBe($catalogItem->id);
});

it('does not link a tariff to a catalog item in a different tenant/facility scope', function (): void {
    $tenantA = makeRadiologyLinkTenant();
    $facilityA = makeRadiologyLinkFacility($tenantA->id);
    $tenantB = makeRadiologyLinkTenant();
    $facilityB = makeRadiologyLinkFacility($tenantB->id);

    $catalogItem = makeRadiologyCatalogItem($tenantA->id, $facilityA->id, 'RAD-TEST-002');
    $tariff = makeUnlinkedRadiologyTariff($tenantB->id, $facilityB->id, 'RAD-TEST-002');

    runRadiologyLinkMigration();

    expect($tariff->fresh()->clinical_catalog_item_id)->toBeNull();
});

it('leaves a tariff unlinked without crashing when no matching catalog item exists', function (): void {
    $tariff = makeUnlinkedRadiologyTariff(null, null, 'RAD-NO-MATCH-001');

    runRadiologyLinkMigration();

    expect($tariff->fresh()->clinical_catalog_item_id)->toBeNull();
});

it('does not touch a tariff that is already linked', function (): void {
    $originalCatalogItem = makeRadiologyCatalogItem(null, null, 'RAD-ORIGINAL-001');
    $decoyCatalogItem = makeRadiologyCatalogItem(null, null, 'RAD-TEST-003');

    $tariff = BillingServiceCatalogItemModel::query()->create([
        'service_code' => 'RAD-TEST-003',
        'service_name' => 'Already Linked Tariff',
        'service_type' => 'radiology',
        'unit' => 'study',
        'base_price' => 50000,
        'currency_code' => 'TZS',
        'status' => 'active',
        'clinical_catalog_item_id' => $originalCatalogItem->id,
    ]);

    runRadiologyLinkMigration();

    expect($tariff->fresh()->clinical_catalog_item_id)->toBe($originalCatalogItem->id)
        ->not->toBe($decoyCatalogItem->id);
});

it('only matches radiology_procedure catalog items, not other catalog types with the same code', function (): void {
    $wrongTypeCatalogItem = ClinicalCatalogItemModel::query()->create([
        'catalog_type' => 'lab_test',
        'code' => 'RAD-TEST-004',
        'name' => 'Coincidentally same code, wrong type',
        'unit' => 'test',
        'status' => 'active',
    ]);
    $tariff = makeUnlinkedRadiologyTariff(null, null, 'RAD-TEST-004');

    runRadiologyLinkMigration();

    expect($tariff->fresh()->clinical_catalog_item_id)->toBeNull();
});
