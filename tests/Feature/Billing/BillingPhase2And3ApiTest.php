<?php

use App\Models\User;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingNhifClaimSubmissionModel;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceModel;
use App\Modules\Billing\Infrastructure\Models\BillingNhifRemittanceItemModel;
use App\Modules\Billing\Infrastructure\Models\BillingPaymentLinkModel;
use App\Modules\Billing\Infrastructure\Models\BillingServiceCatalogItemModel;
use App\Modules\Billing\Infrastructure\Models\BillingSmsLogModel;
use App\Modules\ClaimsInsurance\Infrastructure\Models\ClaimsInsuranceCaseModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makePhaseUser(array $permissions = []): User
{
    $user = User::factory()->create();
    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
    return $user;
}

function seedPhaseScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-PHASE',
        'name' => 'Tanzania Phase Test Hospital',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-PHASE',
        'name' => 'Dar Phase Test Centre',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'billing_officer',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'headers' => [
            'X-Tenant-Code' => 'TZ-PHASE',
            'X-Facility-Code' => 'DAR-PHASE',
        ],
    ];
}

function makePhasePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Juma',
        'last_name' => 'Phase',
        'gender' => 'male',
        'date_of_birth' => '1990-06-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makePhaseInvoice(string $patientId, string $tenantId, string $facilityId, array $overrides = []): BillingInvoiceModel
{
    return BillingInvoiceModel::query()->create(array_merge([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'patient_id' => $patientId,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 50000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 50000,
        'paid_amount' => 0,
        'balance_amount' => 50000,
        'line_items' => [
            ['service_code' => 'OPD-CONSULT', 'service_name' => 'OPD Consultation', 'quantity' => 1, 'unit_price' => 30000, 'total' => 30000],
            ['service_code' => 'LAB-CBC', 'service_name' => 'CBC Test', 'quantity' => 1, 'unit_price' => 20000, 'total' => 20000],
        ],
        'status' => 'draft',
    ], $overrides));
}

function makePhaseClaimCase(string $tenantId, string $facilityId, string $invoiceId, string $patientId, array $overrides = []): ClaimsInsuranceCaseModel
{
    return ClaimsInsuranceCaseModel::query()->create(array_merge([
        'claim_number' => 'CLM-'.strtoupper(Str::random(10)),
        'tenant_id' => $tenantId,
        'facility_id' => $facilityId,
        'invoice_id' => $invoiceId,
        'patient_id' => $patientId,
        'payer_type' => 'nhif',
        'payer_name' => 'NHIF',
        'member_id' => 'NHIF-'.strtoupper(Str::random(8)),
        'status' => 'draft',
        'claim_readiness' => ['ready' => true],
        'claim_amount' => 50000,
        'currency_code' => 'TZS',
    ], $overrides));
}

// ============================================================================
// NHIF e-Claims Submission (Phase 2)
// ============================================================================

describe('NHIF e-Claims submission', function () {

    it('rejects claim submission without permission', function () {
        $user = makePhaseUser();
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345',
                'authorization_number' => 'AUTH67890',
            ])
            ->assertForbidden();
    });

    it('rejects claim submission for non-existent case', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/claims/cases/'.Str::uuid().'/submit', [
                'member_number' => 'NHIF12345',
                'authorization_number' => 'AUTH67890',
            ])
            ->assertNotFound();
    });

    it('rejects claim submission when no invoice is linked to case', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345',
                'authorization_number' => 'AUTH67890',
            ])
            ->assertStatus(422);
    });

    it('validates required payload fields', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['member_number', 'authorization_number']);
    });

    it('stores failed claim submission and returns 422 when NHIF API rejects', function () {
        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/claims/submit' => Http::response([
                'status' => 'rejected',
                'message' => 'Invalid authorization number',
            ], 422),
        ]);

        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345',
                'authorization_number' => 'INVALID-AUTH',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('billing_nhif_claim_submissions', [
            'claims_insurance_case_id' => $case->id,
            'submission_status' => 'rejected',
        ]);
    });

    it('stores successful claim submission and records claim reference', function () {
        $claimRef = 'NHIF-CLM-'.strtoupper(Str::random(10));

        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/claims/submit' => Http::response([
                'claimReference' => $claimRef,
                'status' => 'submitted',
                'message' => 'Claim submitted successfully',
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)
            ->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345',
                'authorization_number' => 'AUTH67890',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.submission_status', 'submitted');

        $this->assertDatabaseHas('billing_nhif_claim_submissions', [
            'claims_insurance_case_id' => $case->id,
            'nhif_claim_reference' => $claimRef,
            'submission_status' => 'submitted',
        ]);

        $case->refresh();
        expect($case->submitted_at)->not->toBeNull();
    });

    it('prevents duplicate claim submission', function () {
        $claimRef = 'NHIF-CLM-'.strtoupper(Str::random(10));

        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/claims/submit' => Http::response([
                'claimReference' => $claimRef, 'status' => 'submitted', 'message' => 'OK',
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345', 'authorization_number' => 'AUTH67890',
            ])->assertOk();

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson("/api/v1/billing-nhif/claims/cases/{$case->id}/submit", [
                'member_number' => 'NHIF12345', 'authorization_number' => 'AUTH67890',
            ])->assertStatus(409);
    });

    it('lists claim submission history', function () {
        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        BillingNhifClaimSubmissionModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'claims_insurance_case_id' => $case->id,
            'billing_invoice_id' => $invoice->id,
            'nhif_claim_reference' => 'REF-001',
            'submission_status' => 'submitted',
            'submitted_amount' => 50000,
            'submitted_at' => now(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-nhif/claims/submissions')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    });

    it('checks claim status via NHIF API', function () {
        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/claims/status*' => Http::response([
                'status' => 'acknowledged', 'message' => 'Claim acknowledged',
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        $submission = BillingNhifClaimSubmissionModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'claims_insurance_case_id' => $case->id,
            'billing_invoice_id' => $invoice->id,
            'nhif_claim_reference' => 'REF-STATUS-CHECK',
            'submission_status' => 'submitted',
            'submitted_amount' => 50000,
            'submitted_at' => now(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson("/api/v1/billing-nhif/claims/submissions/{$submission->id}/status")
            ->assertOk()
            ->assertJsonPath('data.remote_status', 'acknowledged');
    });
});

// ============================================================================
// M-Pesa Self-Payment (Phase 2)
// ============================================================================

describe('M-Pesa self-payment (payment link)', function () {

    it('rejects payment initiation without permission', function () {
        $user = makePhaseUser();
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [
                'billing_invoice_id' => $invoice->id,
                'phone_number' => '0712345678',
            ])
            ->assertForbidden();
    });

    it('validates required payload fields', function () {
        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_invoice_id', 'phone_number']);
    });

    it('returns 404 for non-existent invoice', function () {
        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [
                'billing_invoice_id' => Str::uuid(),
                'phone_number' => '0712345678',
            ])
            ->assertNotFound();
    });

    it('initiates M-Pesa payment and creates payment link record', function () {
        Http::fake([
            'api.selcommobile.com/v1/payments' => Http::response([
                'resultcode' => '000',
                'message' => 'Payment initiated successfully',
                'reference' => 'SELCOM-REF-001',
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);

        $resp = $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [
                'billing_invoice_id' => $invoice->id,
                'phone_number' => '0712345678',
                'amount' => 30000,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['payment_link_id', 'reference_code', 'amount', 'status', 'expires_at']]);

        $this->assertDatabaseHas('billing_payment_links', [
            'billing_invoice_id' => $invoice->id,
            'phone_number' => '0712345678',
            'amount' => 30000,
            'status' => 'pending',
        ]);
    });

    it('prevents duplicate active payment link for same invoice', function () {
        Http::fake([
            'api.selcommobile.com/v1/payments' => Http::response([
                'resultcode' => '000', 'message' => 'OK', 'reference' => 'REF',
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [
                'billing_invoice_id' => $invoice->id, 'phone_number' => '0712345678',
            ])->assertOk();

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-payments/mpesa/initiate', [
                'billing_invoice_id' => $invoice->id, 'phone_number' => '0712345678',
            ])->assertStatus(409);
    });

    it('checks M-Pesa payment status', function () {
        $user = makePhaseUser(['billing.payments.read']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        $link = BillingPaymentLinkModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'phone_number' => '0712345678',
            'amount' => 50000,
            'currency' => 'TZS',
            'reference_code' => 'PAY-TEST-001',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson("/api/v1/billing-payments/mpesa/status/{$link->reference_code}")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');
    });

    it('lists payment links', function () {
        $user = makePhaseUser(['billing.payments.read']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        BillingPaymentLinkModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'phone_number' => '0712345678',
            'amount' => 50000,
            'currency' => 'TZS',
            'reference_code' => 'PAY-LIST-001',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-payments/mpesa/links')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    });
});

// ============================================================================
// NHIF Tariff Sync (Phase 2)
// ============================================================================

describe('NHIF tariff sync', function () {

    it('rejects tariff import without permission', function () {
        $user = makePhaseUser();
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/tariffs/import')
            ->assertForbidden();
    });

    it('previews tariff schedule from NHIF API', function () {
        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/tariffs*' => Http::response([
                'version' => '2026.1',
                'effectiveDate' => '2026-01-01',
                'items' => [
                    ['code' => 'OPD-001', 'name' => 'General Consultation', 'price' => 15000, 'category' => 'OPD'],
                    ['code' => 'LAB-001', 'name' => 'Malaria Test', 'price' => 5000, 'category' => 'Laboratory'],
                ],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-nhif/tariffs/preview')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_items', 2);
    });

    it('imports tariff schedule and creates catalog items', function () {
        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/tariffs*' => Http::response([
                'version' => '2026.1',
                'effectiveDate' => '2026-01-01',
                'items' => [
                    ['code' => 'OPD-001', 'name' => 'General Consultation', 'price' => 15000, 'category' => 'OPD'],
                ],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/tariffs/import')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items_imported', 1);

        $this->assertDatabaseHas('billing_nhif_tariff_imports', [
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'tariff_version' => '2026.1',
        ]);

        $this->assertDatabaseHas('billing_service_catalog_items', [
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'service_name' => 'General Consultation',
        ]);
    });

    it('updates existing catalog item on re-import', function () {
        Http::fake([
            'api.nhif.or.tz/auth/token' => Http::response(['access_token' => 'test-token'], 200),
            'api.nhif.or.tz/tariffs*' => Http::response([
                'version' => '2026.2',
                'effectiveDate' => '2026-06-01',
                'items' => [
                    ['code' => 'OPD-001', 'name' => 'General Consultation', 'price' => 18000, 'category' => 'OPD'],
                ],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        BillingServiceCatalogItemModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'service_code' => 'OPD-001',
            'service_name' => 'General Consultation',
            'service_type' => 'OPD',
            'department' => 'OPD',
            'base_price' => 15000,
            'currency_code' => 'TZS',
            'codes' => ['nhif_code' => 'OPD-001', 'nhif_tariff' => 15000],
            'status' => 'active',
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/tariffs/import')
            ->assertOk()
            ->assertJsonPath('data.items_updated', 1);

        $item = BillingServiceCatalogItemModel::query()
            ->where('tenant_id', $scope['tenantId'])
            ->where('facility_id', $scope['facilityId'])
            ->where('service_code', 'OPD-001')
            ->first();

        expect((float) $item->base_price)->toBe(18000.0);
    });

    it('shows import history', function () {
        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);

        DB::table('billing_nhif_tariff_imports')->insert([
            'id' => Str::uuid(),
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'tariff_version' => '2026.1',
            'effective_date' => '2026-01-01',
            'items_imported' => 5,
            'items_updated' => 0,
            'items_skipped' => 0,
            'import_log' => '[]',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-nhif/tariffs/history')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    });

    it('lists catalog items with NHIF codes', function () {
        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);

        BillingServiceCatalogItemModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'service_code' => 'OPD-001',
            'service_name' => 'General Consultation',
            'service_type' => 'OPD',
            'department' => 'OPD',
            'base_price' => 15000,
            'currency_code' => 'TZS',
            'codes' => ['nhif_code' => 'OPD-001'],
            'status' => 'active',
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-nhif/tariffs/catalog')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    });
});

// ============================================================================
// NHIF Remittance Processor (Phase 3)
// ============================================================================

describe('NHIF remittance processor', function () {

    it('rejects remittance upload without permission', function () {
        $user = makePhaseUser();
        $scope = seedPhaseScope($user->id);

        $file = UploadedFile::fake()->createWithContent('remittance.csv', 'claim_reference,settled_amount\nCLM-001,25000');

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', [
                'file' => $file,
            ])
            ->assertForbidden();
    });

    it('validates required file field', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    });

    it('processes CSV remittance and reconciles matched claims', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id, [
            'reconciliation_status' => 'pending',
        ]);

        BillingNhifClaimSubmissionModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'claims_insurance_case_id' => $case->id,
            'billing_invoice_id' => $invoice->id,
            'nhif_claim_reference' => 'CLM-MATCH-001',
            'submission_status' => 'submitted',
            'submitted_amount' => 50000,
            'submitted_at' => now(),
        ]);

        $csvContent = "claim_reference,member_number,patient_name,claimed_amount,approved_amount,rejected_amount,settled_amount,decision,decision_reason\n";
        $csvContent .= "CLM-MATCH-001,NHIF123,Juma,50000,45000,5000,45000,approved,Approved in full\n";
        $file = UploadedFile::fake()->createWithContent('remittance.csv', $csvContent);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', [
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.matched_claims', 1)
            ->assertJsonPath('data.total_claims', 1);

        $this->assertDatabaseHas('billing_nhif_remittances', [
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'total_claims' => 1,
            'matched_claims' => 1,
        ]);

        $this->assertDatabaseHas('billing_nhif_remittance_items', [
            'claim_reference' => 'CLM-MATCH-001',
            'reconciliation_status' => 'matched',
        ]);

        $case->refresh();
        expect($case->reconciliation_status)->toBe('matched');
        expect((float) $case->settled_amount)->toBe(45000.0);
    });

    it('flags unmatched claims in remittance', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        $csvContent = "claim_reference,member_number,patient_name,claimed_amount,approved_amount,rejected_amount,settled_amount,decision,decision_reason\n";
        $csvContent .= "CLM-UNKNOWN-001,NHIF999,Unknown,50000,0,50000,0,rejected,Claim not found\n";
        $file = UploadedFile::fake()->createWithContent('remittance.csv', $csvContent);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', [
                'file' => $file,
            ])
            ->assertOk()
            ->assertJsonPath('data.matched_claims', 0)
            ->assertJsonPath('data.total_claims', 1);

        $this->assertDatabaseHas('billing_nhif_remittance_items', [
            'claim_reference' => 'CLM-UNKNOWN-001',
            'reconciliation_status' => 'unmatched',
        ]);
    });

    it('rejects duplicate remittance file upload', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);

        $csvContent = "claim_reference,settled_amount\nCLM-DUP-001,10000\n";
        $file = UploadedFile::fake()->createWithContent('remittance.csv', $csvContent);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', ['file' => $file])
            ->assertOk();

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', ['file' => $file])
            ->assertStatus(409);
    });

    it('processes JSON remittance file', function () {
        $user = makePhaseUser(['billing.insurance.manage']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'issued']);
        $case = makePhaseClaimCase($scope['tenantId'], $scope['facilityId'], $invoice->id, $patient->id);

        BillingNhifClaimSubmissionModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'claims_insurance_case_id' => $case->id,
            'billing_invoice_id' => $invoice->id,
            'nhif_claim_reference' => 'CLM-JSON-001',
            'submission_status' => 'submitted',
            'submitted_amount' => 30000,
            'submitted_at' => now(),
        ]);

        $jsonContent = json_encode([
            'items' => [
                [
                    'claimReference' => 'CLM-JSON-001',
                    'memberNumber' => 'NHIF-JSON',
                    'patientName' => 'Juma JSON',
                    'claimedAmount' => 30000,
                    'approvedAmount' => 28000,
                    'rejectedAmount' => 2000,
                    'settledAmount' => 28000,
                    'decision' => 'approved',
                    'decisionReason' => 'Partial approval',
                ],
            ],
        ]);

        $file = UploadedFile::fake()->createWithContent('remittance.json', $jsonContent);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-nhif/remittances/upload', [
                'file' => $file,
                'format' => 'json',
            ])
            ->assertOk()
            ->assertJsonPath('data.matched_claims', 1);
    });

    it('lists remittance upload history', function () {
        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);

        BillingNhifRemittanceModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'remittance_reference' => 'REM-HIST-001',
            'remittance_date' => now()->toDateString(),
            'total_amount' => 50000,
            'total_claims' => 2,
            'matched_claims' => 2,
            'matched_amount' => 50000,
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-nhif/remittances/history')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');
    });

    it('shows remittance detail with items', function () {
        $user = makePhaseUser(['billing.insurance.read']);
        $scope = seedPhaseScope($user->id);

        $remittance = BillingNhifRemittanceModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'remittance_reference' => 'REM-DETAIL-001',
            'remittance_date' => now()->toDateString(),
            'total_amount' => 30000,
            'total_claims' => 1,
            'matched_claims' => 1,
            'matched_amount' => 30000,
            'status' => 'completed',
            'processed_at' => now(),
        ]);

        BillingNhifRemittanceItemModel::query()->create([
            'billing_nhif_remittance_id' => $remittance->id,
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'claim_reference' => 'CLM-DETAIL-001',
            'settled_amount' => 30000,
            'reconciliation_status' => 'matched',
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson("/api/v1/billing-nhif/remittances/{$remittance->id}")
            ->assertOk()
            ->assertJsonPath('data.remittance_reference', 'REM-DETAIL-001')
            ->assertJsonCount(1, 'data.items');
    });
});

// ============================================================================
// SMS Integration (Phase 3)
// ============================================================================

describe('SMS integration', function () {

    it('rejects SMS sending without permission', function () {
        $user = makePhaseUser();
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/custom', [
                'phone_number' => '0712345678',
                'message' => 'Test SMS',
            ])
            ->assertForbidden();
    });

    it('sends custom SMS via provider and logs it', function () {
        Http::fake([
            'api.africastalking.com/version1/messaging' => Http::response([
                'SMSMessageData' => [
                    'Recipients' => [
                        ['status' => 'Success', 'messageId' => 'AT-MSG-001'],
                    ],
                ],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/custom', [
                'phone_number' => '0712345678',
                'message' => 'Your payment of TZS 50,000 was received. Thank you.',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('billing_sms_logs', [
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'phone_number' => '0712345678',
            'message_type' => 'custom',
            'status' => 'sent',
        ]);
    });

    it('validates custom SMS fields', function () {
        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/custom', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number', 'message']);
    });

    it('sends payment link SMS for existing payment link', function () {
        Http::fake([
            'api.africastalking.com/version1/messaging' => Http::response([
                'SMSMessageData' => ['Recipients' => [['status' => 'Success', 'messageId' => 'AT-PL-001']]],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        $link = BillingPaymentLinkModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'phone_number' => '0712345678',
            'amount' => 50000,
            'currency' => 'TZS',
            'reference_code' => 'PAY-SMS-001',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/payment-link', [
                'billing_payment_link_id' => $link->id,
                'phone_number' => '0712345678',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('billing_sms_logs', [
            'message_type' => 'payment_link',
            'billing_payment_link_id' => $link->id,
        ]);
    });

    it('sends receipt SMS for existing invoice', function () {
        Http::fake([
            'api.africastalking.com/version1/messaging' => Http::response([
                'SMSMessageData' => ['Recipients' => [['status' => 'Success', 'messageId' => 'AT-RC-001']]],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId'], ['status' => 'paid', 'paid_amount' => 50000]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/receipt', [
                'billing_invoice_id' => $invoice->id,
                'phone_number' => '0712345678',
                'payment_reference' => 'MPESA-REF-001',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('billing_sms_logs', [
            'message_type' => 'receipt',
            'billing_invoice_id' => $invoice->id,
        ]);
    });

    it('returns 404 when payment link not found for SMS', function () {
        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/payment-link', [
                'billing_payment_link_id' => Str::uuid(),
                'phone_number' => '0712345678',
            ])
            ->assertNotFound();
    });

    it('returns 404 when invoice not found for receipt SMS', function () {
        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/receipt', [
                'billing_invoice_id' => Str::uuid(),
                'phone_number' => '0712345678',
            ])
            ->assertNotFound();
    });

    it('logs failed SMS attempt', function () {
        Http::fake([
            'api.africastalking.com/version1/messaging' => Http::response([
                'SMSMessageData' => ['Recipients' => [['status' => 'Failed']]],
            ], 200),
        ]);

        $user = makePhaseUser(['billing.payments.record']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        $link = BillingPaymentLinkModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'phone_number' => '0712345678',
            'amount' => 50000,
            'currency' => 'TZS',
            'reference_code' => 'PAY-FAIL-001',
            'status' => 'pending',
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->postJson('/api/v1/billing-sms/payment-link', [
                'billing_payment_link_id' => $link->id,
                'phone_number' => '0712345678',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('billing_sms_logs', [
            'billing_payment_link_id' => $link->id,
            'status' => 'failed',
        ]);
    });

    it('views SMS log with filters', function () {
        $user = makePhaseUser(['billing.payments.read']);
        $scope = seedPhaseScope($user->id);
        $patient = makePhasePatient();
        $invoice = makePhaseInvoice($patient->id, $scope['tenantId'], $scope['facilityId']);

        BillingSmsLogModel::query()->create([
            'tenant_id' => $scope['tenantId'],
            'facility_id' => $scope['facilityId'],
            'phone_number' => '255712345678',
            'message_type' => 'receipt',
            'message' => 'Payment received',
            'provider' => 'AfricasTalkingSmsProvider',
            'status' => 'sent',
            'billing_invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'sent_at' => now(),
        ]);

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-sms/log?message_type=receipt')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-sms/log?status=sent')
            ->assertOk()
            ->assertJsonCount(1, 'data.data');

        $this->actingAs($user)->withHeaders($scope['headers'])
            ->getJson('/api/v1/billing-sms/log?status=failed')
            ->assertOk()
            ->assertJsonCount(0, 'data.data');
    });
});
