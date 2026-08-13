<?php

use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Support\CatalogGovernance\EmptyDiagnosisCatalogAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Coverage for C-10 Option C (decided — see
 * reports/clinical-note-audit/16-remediation-options-c8-c9-c10-c12.md):
 * EmptyDiagnosisCatalogAuditor flags any facility with zero active
 * diagnosis-terminology catalog entries, by reusing
 * DiagnosisTerminologyLookupServiceInterface (the real validation-path
 * service) against a temporarily-rebound scope context per facility —
 * not a second implementation of the catalog's scoping rules.
 */
function auditorTenant(): TenantModel
{
    return TenantModel::query()->create([
        'code' => 'TAUD'.strtoupper(Str::random(6)),
        'name' => 'Auditor Fixture Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function auditorFacility(string $tenantId, array $overrides = []): FacilityModel
{
    return FacilityModel::query()->create(array_merge([
        'tenant_id' => $tenantId,
        'code' => 'FAUD'.strtoupper(Str::random(6)),
        'name' => 'Auditor Fixture Facility',
        'facility_type' => 'hospital',
        'status' => 'active',
    ], $overrides));
}

it('flags a facility with zero active diagnosis catalog entries', function (): void {
    $tenant = auditorTenant();
    $facility = auditorFacility($tenant->id);

    $findings = app(EmptyDiagnosisCatalogAuditor::class)->audit();

    $facilityIds = collect($findings)->pluck('sourceId')->all();
    expect($facilityIds)->toContain($facility->id);
});

it('does not flag a facility with an active diagnosis catalog entry scoped to it', function (): void {
    $tenant = auditorTenant();
    $facility = auditorFacility($tenant->id);

    ClinicalCatalogItemModel::query()->create([
        'tenant_id' => $tenant->id,
        'facility_id' => $facility->id,
        'catalog_type' => 'diagnosis_code',
        'code' => 'R52',
        'name' => 'Pain, unspecified',
        'status' => 'active',
    ]);

    $findings = app(EmptyDiagnosisCatalogAuditor::class)->audit();

    $facilityIds = collect($findings)->pluck('sourceId')->all();
    expect($facilityIds)->not->toContain($facility->id);
});

it('does not flag a facility covered by a global (tenant/facility-null) active catalog entry', function (): void {
    $tenant = auditorTenant();
    $facility = auditorFacility($tenant->id);

    ClinicalCatalogItemModel::query()->create([
        'tenant_id' => null,
        'facility_id' => null,
        'catalog_type' => 'diagnosis_code',
        'code' => 'R52',
        'name' => 'Pain, unspecified',
        'status' => 'active',
    ]);

    $findings = app(EmptyDiagnosisCatalogAuditor::class)->audit();

    $facilityIds = collect($findings)->pluck('sourceId')->all();
    expect($facilityIds)->not->toContain($facility->id);
});

it('persists findings to catalog_integrity_audit_findings with the expected shape', function (): void {
    $tenant = auditorTenant();
    $facility = auditorFacility($tenant->id);

    $auditor = app(EmptyDiagnosisCatalogAuditor::class);
    $auditor->writeAuditFindings($auditor->audit());

    $row = DB::table('catalog_integrity_audit_findings')
        ->where('source_id', $facility->id)
        ->where('issue_code', 'diagnosis_catalog.empty_for_facility')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->severity)->toBe('warning');
    expect($row->module)->toBe('medical_records');
    expect($row->source_table)->toBe('facilities');
});

it('restores normal scope resolution after auditing, not leaving the last facility bound', function (): void {
    $tenant = auditorTenant();
    auditorFacility($tenant->id);

    app(EmptyDiagnosisCatalogAuditor::class)->audit();

    expect(app(\App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface::class))
        ->toBeInstanceOf(\App\Modules\Platform\Infrastructure\Services\RequestCurrentPlatformScopeContext::class);
});
