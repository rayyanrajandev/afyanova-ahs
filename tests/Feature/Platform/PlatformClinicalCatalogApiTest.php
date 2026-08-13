<?php

use App\Models\User;
use App\Modules\Platform\Infrastructure\Models\ChargeableItemModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemAuditLogModel;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);
});

function clinicalCatalogPayload(array $overrides = []): array
{
    return array_merge([
        'code' => 'LAB-CBC-001',
        'name' => 'Complete Blood Count',
        'category' => 'hematology',
        'unit' => 'panel',
        'description' => 'Standard hematology profile.',
        'metadata' => [
            'sampleType' => 'blood',
        ],
    ], $overrides);
}

function makeClinicalCatalogActor(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function createLabCatalogItem(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload($payload))
        ->assertCreated()
        ->json('data');
}

function createTheatreCatalogItem(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/theatre-procedures', clinicalCatalogPayload($payload))
        ->assertCreated()
        ->json('data');
}

function createFormularyCatalogItem(User $user, array $payload = []): array
{
    return test()->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/formulary-items', clinicalCatalogPayload($payload))
        ->assertCreated()
        ->json('data');
}

function createBillingServiceCatalogRow(array $overrides = []): array
{
    $row = array_merge([
        'id' => (string) Str::uuid(),
        'tenant_id' => null,
        'facility_id' => null,
        'clinical_catalog_item_id' => null,
        'service_code' => 'LAB-CBC-TARIFF-001',
        'tariff_version' => 1,
        'service_name' => 'CBC Price',
        'service_type' => 'laboratory',
        'department_id' => null,
        'department' => 'Laboratory',
        'unit' => 'test',
        'base_price' => '15000.00',
        'currency_code' => 'TZS',
        'tax_rate_percent' => '0.00',
        'is_taxable' => false,
        'effective_from' => now()->subDay(),
        'effective_to' => null,
        'description' => 'Clinical catalog linkage test tariff',
        'metadata' => null,
        'status' => 'active',
        'status_reason' => null,
        'supersedes_billing_service_catalog_item_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);

    DB::table('billing_service_catalog_items')->insert($row);

    return $row;
}

it('requires authentication for clinical catalog item creation', function (): void {
    $this->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload())
        ->assertUnauthorized();
});

it('forbids clinical catalog list without read permission', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests')
        ->assertForbidden();
});

it('creates clinical catalog item with normalized code and active status', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload([
            'code' => 'lab-cbc-001',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.catalogType', 'lab_test')
        ->assertJsonPath('data.code', 'LAB-CBC-001')
        ->assertJsonPath('data.status', 'active');
});

it('automatically creates a matching chargeable item, so no manual pricing backfill is ever needed for a new catalog item', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);

    $catalogItemId = $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload([
            'code' => 'LAB-AUTO-SYNC-001',
            'name' => 'Auto-Sync Test Panel',
        ]))
        ->assertCreated()
        ->json('data.id');

    $chargeableItem = ChargeableItemModel::query()->find($catalogItemId);

    expect($chargeableItem)->not->toBeNull()
        ->and($chargeableItem->clinical_catalog_item_id)->toBe($catalogItemId)
        ->and($chargeableItem->catalog_type)->toBe('lab_test')
        ->and($chargeableItem->charge_model)->toBe('flat')
        ->and($chargeableItem->code)->toBe('LAB-AUTO-SYNC-001')
        ->and($chargeableItem->name)->toBe('Auto-Sync Test Panel')
        ->and($chargeableItem->status)->toBe('active');
});

it('re-syncs the linked chargeable item when an existing catalog item changes status', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $catalogItemId = createLabCatalogItem($user, ['code' => 'LAB-AUTO-SYNC-002'])['id'];

    $this->actingAs($user)
        ->patchJson("/api/v1/platform/admin/clinical-catalogs/lab-tests/{$catalogItemId}/status", [
            'status' => 'inactive',
            'reason' => 'Retired for this test.',
        ])
        ->assertOk();

    $chargeableItem = ChargeableItemModel::query()->find($catalogItemId);

    expect($chargeableItem->status)->toBe('inactive');
});

it('returns billing link summary when clinical item references an active billing price', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $billingRow = createBillingServiceCatalogRow([
        'service_code' => 'LAB-CBC-001',
        'service_name' => 'Complete Blood Count Price',
        'base_price' => '18000.00',
    ]);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload([
            'billingServiceCode' => 'lab-cbc-001',
        ]))
        ->assertCreated()
        ->assertJsonPath('data.billingServiceCode', 'LAB-CBC-001')
        ->assertJsonPath('data.billingLinkStatus', 'linked')
        ->assertJsonPath('data.billingLink.status', 'linked')
        ->assertJsonPath('data.billingLink.serviceCode', 'LAB-CBC-001')
        ->assertJsonPath('data.billingLink.item.id', $billingRow['id'])
        ->assertJsonPath('data.billingLink.item.serviceName', 'Complete Blood Count Price')
        ->assertJsonPath('data.billingLink.item.basePrice', '18000.00')
        ->assertJsonPath('data.billingLink.item.currencyCode', 'TZS');
});

it('rejects duplicate clinical catalog code in the same scope and type', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    createLabCatalogItem($user);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('lists and filters clinical catalog items', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
    ]);

    createLabCatalogItem($user, [
        'code' => 'LAB-CBC-002',
        'name' => 'CBC Follow-up',
        'category' => 'hematology',
    ]);
    createLabCatalogItem($user, [
        'code' => 'LAB-GLU-001',
        'name' => 'Blood Glucose',
        'category' => 'chemistry',
        'billingServiceCode' => 'LAB-GLU-001',
    ]);
    createBillingServiceCatalogRow([
        'service_code' => 'LAB-GLU-001',
        'service_name' => 'Blood Glucose Price',
        'base_price' => '9000.00',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests?q=glucose&category=chemistry')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'LAB-GLU-001')
        ->assertJsonPath('data.0.billingLinkStatus', 'linked')
        ->assertJsonPath('data.0.billingLink.item.serviceName', 'Blood Glucose Price');
});

it('searches clinical catalog items case-insensitively with partial medicine names', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-formulary',
    ]);

    createFormularyCatalogItem($user, [
        'code' => 'MED-ALB-400',
        'name' => 'Albendazole 400mg Tablet',
        'category' => 'antiparasitic',
        'unit' => 'tablet',
    ]);
    createFormularyCatalogItem($user, [
        'code' => 'MED-ACET-500',
        'name' => 'Paracetamol 500mg Tablet',
        'category' => 'analgesic',
        'unit' => 'tablet',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/formulary-items?q=albe')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'MED-ALB-400');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/formulary-items?q=ALBENDA')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.name', 'Albendazole 400mg Tablet');

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/formulary-items?q=al')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('returns clinical catalog status counts', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
    ]);

    $active = createLabCatalogItem($user, ['code' => 'LAB-HBA1C-001', 'name' => 'HbA1c']);
    createLabCatalogItem($user, ['code' => 'LAB-TSH-001', 'name' => 'TSH']);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$active['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporarily suspended',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/status-counts')
        ->assertOk()
        ->assertJsonPath('data.active', 1)
        ->assertJsonPath('data.inactive', 1)
        ->assertJsonPath('data.retired', 0)
        ->assertJsonPath('data.total', 2);
});

it('updates clinical catalog item fields', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'], [
            'name' => 'Complete Blood Count Extended',
            'unit' => 'test',
            'metadata' => ['sampleType' => 'venous blood'],
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Complete Blood Count Extended')
        ->assertJsonPath('data.unit', 'test')
        ->assertJsonPath('data.metadata.sampleType', 'venous blood');
});

it('shows pending price status when billing service code has no matching tariff yet', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
    ]);
    $created = createLabCatalogItem($user, [
        'code' => 'LAB-ESR-001',
        'name' => 'ESR',
        'billingServiceCode' => 'LAB-ESR-001',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.billingServiceCode', 'LAB-ESR-001')
        ->assertJsonPath('data.billingLinkStatus', 'pending_price')
        ->assertJsonPath('data.billingLink.status', 'pending_price')
        ->assertJsonPath('data.billingLink.serviceCode', 'LAB-ESR-001')
        ->assertJsonPath('data.billingLink.item', null);
});

it('requires reason when retiring clinical catalog item', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/status', [
            'status' => 'retired',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('updates clinical catalog status', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/status', [
            'status' => 'retired',
            'reason' => 'Replaced by newer assay panel',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.statusReason', 'Replaced by newer assay panel');
});

it('lists clinical catalog audit logs when authorized', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.manage-lab-tests',
        'platform.clinical-catalog.view-audit-logs',
    ]);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/audit-logs')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.action', 'platform.clinical-catalog-item.created');
});

it('exports clinical catalog audit logs as csv', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.manage-lab-tests',
        'platform.clinical-catalog.view-audit-logs',
    ]);
    $created = createLabCatalogItem($user);

    $response = $this->actingAs($user)
        ->get('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/audit-logs/export');

    $response->assertOk();
    $response->assertHeader('X-Audit-CSV-Schema-Version', 'audit-log-csv.v1');
    expect($response->streamedContent())->toContain('platform.clinical-catalog-item.created');
});

it('forbids clinical catalog audit logs without permission', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/audit-logs')
        ->assertForbidden();
});

it('enforces type-specific routing for catalog item reads', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
    ]);
    $created = createLabCatalogItem($user);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/radiology-procedures/'.$created['id'])
        ->assertNotFound();
});

it('creates theatre procedure catalog item with dedicated catalog type', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-theatre-procedures']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/theatre-procedures', clinicalCatalogPayload([
            'code' => 'THR-HRN-001',
            'name' => 'Hernia Repair',
            'category' => 'general_surgery',
            'unit' => 'procedure',
            'metadata' => [
                'specialty' => 'general_surgery',
                'anesthesiaType' => 'general',
            ],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.catalogType', 'theatre_procedure')
        ->assertJsonPath('data.code', 'THR-HRN-001')
        ->assertJsonPath('data.name', 'Hernia Repair');
});

it('lists theatre procedure catalog items with theatre permission', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-theatre-procedures',
    ]);

    createTheatreCatalogItem($user, [
        'code' => 'THR-APP-010',
        'name' => 'Appendectomy',
        'category' => 'general_surgery',
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/theatre-procedures?q=append&category=general_surgery')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', 'THR-APP-010');
});

it('enforces type-specific manage permissions', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-radiology-procedures']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests', clinicalCatalogPayload())
        ->assertForbidden();
});

it('does not allow radiology permission to manage theatre procedure catalog items', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-radiology-procedures']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/theatre-procedures', clinicalCatalogPayload([
            'code' => 'THR-CHL-004',
            'name' => 'Laparoscopic Cholecystectomy',
        ]))
        ->assertForbidden();
});

it('writes clinical catalog status transition parity metadata in audit logs', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.manage-lab-tests',
        'platform.clinical-catalog.view-audit-logs',
    ]);
    $created = createLabCatalogItem($user, [
        'code' => 'LAB-PARITY-001',
        'name' => 'Parity Check Test',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/status', [
            'status' => 'inactive',
            'reason' => 'Temporary suspension',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    $statusAudit = ClinicalCatalogItemAuditLogModel::query()
        ->where('platform_clinical_catalog_item_id', $created['id'])
        ->where('action', 'platform.clinical-catalog-item.status.updated')
        ->latest('created_at')
        ->first();

    expect($statusAudit)->not->toBeNull();
    expect($statusAudit?->metadata ?? [])->toMatchArray([
        'catalogType' => 'lab_test',
        'transition' => [
            'from' => 'active',
            'to' => 'inactive',
        ],
        'reason_required' => true,
        'reason_provided' => true,
    ]);

    $auditResponse = $this->actingAs($user)
        ->getJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'].'/audit-logs?perPage=10')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    expect(collect($auditResponse->json('data'))->pluck('action')->all())
        ->toContain('platform.clinical-catalog-item.status.updated');
});

it('rejects clinical catalog detail update when status fields are provided', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $created = createLabCatalogItem($user, [
        'code' => 'LAB-GUARD-001',
        'name' => 'Guardrail Test',
    ]);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/'.$created['id'], [
            'name' => 'Guardrail Test Updated',
            'status' => 'inactive',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('exports clinical catalog items as csv when authorized', function (): void {
    $user = makeClinicalCatalogActor([
        'platform.clinical-catalog.read',
        'platform.clinical-catalog.manage-lab-tests',
    ]);
    createLabCatalogItem($user, ['code' => 'LAB-EXPORT-001', 'name' => 'Exportable Test']);

    $response = $this->actingAs($user)
        ->get('/api/v1/platform/admin/clinical-catalogs/lab-tests/export');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/csv');
    expect($response->streamedContent())->toContain('LAB-EXPORT-001');
});

it('downloads clinical catalog import template csv when authorized', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.read']);

    $response = $this->actingAs($user)
        ->get('/api/v1/platform/admin/clinical-catalogs/lab-tests/import-template');

    $response->assertOk();
    expect($response->streamedContent())->toContain('code');
    expect($response->streamedContent())->toContain('sample_type');
});

it('validates clinical catalog bulk import in dry run mode', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/bulk-import', [
            'dryRun' => true,
            'mode' => 'create',
            'rows' => [
                [
                    'rowNumber' => 2,
                    'values' => [
                        'code' => 'LAB-BULK-001',
                        'name' => 'Bulk Import Test',
                        'category' => 'chemistry',
                        'unit' => 'test',
                        'status' => 'active',
                    ],
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.created_count', 1)
        ->assertJsonPath('data.results.0.outcome', 'would_create');
});

it('bulk updates clinical catalog item status when authorized', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-lab-tests']);
    $first = createLabCatalogItem($user, ['code' => 'LAB-BULK-STATUS-001', 'name' => 'Bulk Status A']);
    $second = createLabCatalogItem($user, ['code' => 'LAB-BULK-STATUS-002', 'name' => 'Bulk Status B']);

    $this->actingAs($user)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/lab-tests/bulk-status', [
            'itemIds' => [$first['id'], $second['id']],
            'status' => 'inactive',
            'reason' => 'Bulk deactivation test',
        ])
        ->assertOk()
        ->assertJsonPath('data.updatedCount', 2);
});

// Inventory_MasterData_Alignment_Plan.md Phase 6: manage-formulary is enough to create
// or edit a drug definition, but not enough to flag it cold-chain or controlled-substance
// -- that needs the separate, narrower inventory.procurement.manage-compliance permission.
it('blocks setting cold-chain or controlled-substance on a formulary item without the compliance permission', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-formulary']);

    $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/formulary-items', clinicalCatalogPayload([
            'code' => 'MED-COMPLIANCE-001',
            'name' => 'Test Cold Chain Vaccine',
            'requiresColdChain' => true,
            'isControlledSubstance' => true,
            'controlledSubstanceSchedule' => 'schedule_II',
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain', 'isControlledSubstance']);
});

it('allows setting cold-chain or controlled-substance on a formulary item with the compliance permission', function (): void {
    $user = makeClinicalCatalogActor(['platform.clinical-catalog.manage-formulary', 'inventory.procurement.manage-compliance']);

    $item = $this->actingAs($user)
        ->postJson('/api/v1/platform/admin/clinical-catalogs/formulary-items', clinicalCatalogPayload([
            'code' => 'MED-COMPLIANCE-002',
            'name' => 'Test Cold Chain Vaccine 2',
            'requiresColdChain' => true,
            'isControlledSubstance' => true,
            'controlledSubstanceSchedule' => 'schedule_II',
        ]))
        ->assertCreated()
        ->json('data');

    expect($item['requiresColdChain'])->toBeTrue();
    expect($item['isControlledSubstance'])->toBeTrue();
    expect($item['controlledSubstanceSchedule'])->toBe('schedule_II');
});

// Regression test for a bug caught while building the frontend gate on top of this
// permission: the admin form resends every field on every save (not a diff), so a
// naive "reject if present and true" check would have blocked this routine edit --
// changing an unrelated field on an item a compliance-permissioned user had already,
// correctly, flagged cold-chain. Only a genuine true/false transition should require
// the permission.
it('allows editing an unrelated field on an already-compliance-flagged formulary item without the compliance permission', function (): void {
    $complianceUser = makeClinicalCatalogActor(['platform.clinical-catalog.manage-formulary', 'inventory.procurement.manage-compliance']);
    $item = createFormularyCatalogItem($complianceUser, [
        'code' => 'MED-COMPLIANCE-003',
        'name' => 'Test Cold Chain Vaccine 3',
        'requiresColdChain' => true,
        'isControlledSubstance' => true,
        'controlledSubstanceSchedule' => 'schedule_II',
    ]);

    $formularyOnlyUser = makeClinicalCatalogActor(['platform.clinical-catalog.manage-formulary']);

    $updated = $this->actingAs($formularyOnlyUser)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/formulary-items/'.$item['id'], [
            'description' => 'Updated description, unrelated to compliance fields',
            'requiresColdChain' => true,
            'isControlledSubstance' => true,
            'controlledSubstanceSchedule' => 'schedule_II',
        ])
        ->assertOk()
        ->json('data');

    expect($updated['description'])->toBe('Updated description, unrelated to compliance fields');
    expect($updated['requiresColdChain'])->toBeTrue();
    expect($updated['isControlledSubstance'])->toBeTrue();

    // But actually flipping either one still requires the permission.
    $this->actingAs($formularyOnlyUser)
        ->patchJson('/api/v1/platform/admin/clinical-catalogs/formulary-items/'.$item['id'], [
            'requiresColdChain' => false,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['requiresColdChain']);
});
