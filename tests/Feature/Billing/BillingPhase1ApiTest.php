<?php

use App\Models\User;
use App\Http\Middleware\EnsureFacilitySubscriptionEntitlement;
use App\Http\Middleware\EnsureMappedFacilitySubscriptionEntitlement;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\BillingInvoicePaymentModel;
use App\Modules\Billing\Infrastructure\Models\BillingPayerContractModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use App\Modules\Billing\Infrastructure\Models\GLJournalEntryModel;
use App\Modules\Billing\Infrastructure\Models\PatientInsuranceModel;
use App\Modules\Billing\Infrastructure\Models\RevenueRecognitionModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureFacilitySubscriptionEntitlement::class);
    $this->withoutMiddleware(EnsureMappedFacilitySubscriptionEntitlement::class);
});

function makeBillingPhaseUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

/**
 * @return array{tenantId:string,facilityId:string,headers:array<string,string>}
 */
function seedBillingPhaseScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-BILL',
        'name' => 'Tanzania Private Hospital Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-BILL',
        'name' => 'Dar Billing Centre',
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
            'X-Tenant-Code' => 'TZ-BILL',
            'X-Facility-Code' => 'DAR-BILL',
        ],
    ];
}

function makeBillingPhasePatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-'.strtoupper(Str::random(8)),
        'first_name' => 'Neema',
        'last_name' => 'Billing',
        'gender' => 'female',
        'date_of_birth' => '1992-02-14',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function makeBillingPhaseInvoice(string $patientId, array $overrides = []): BillingInvoiceModel
{
    return BillingInvoiceModel::query()->create(array_merge([
        'invoice_number' => 'INV-'.strtoupper(Str::random(10)),
        'patient_id' => $patientId,
        'invoice_date' => now()->toDateTimeString(),
        'currency_code' => 'TZS',
        'subtotal_amount' => 100000,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total_amount' => 100000,
        'paid_amount' => 0,
        'balance_amount' => 100000,
        'line_items' => [],
        'status' => 'draft',
    ], $overrides));
}

it('loads provider-managed cash billing routes and handles account lifecycle', function (): void {
    $user = makeBillingPhaseUser([
        'billing.cash-accounts.read',
        'billing.cash-accounts.manage',
    ]);

    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();

    $account = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/cash-patients', [
            'patient_id' => $patient->id,
            'currency_code' => 'TZS',
            'notes' => 'Walk-in account',
        ])
        ->assertCreated()
        ->json('data');

    expect($account['tenant_id'])->toBe($scope['tenantId']);
    expect($account['facility_id'])->toBe($scope['facilityId']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account['id']}/charges", [
            'service_name' => 'Emergency consultation',
            'quantity' => 2,
            'unit_price' => 15000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.charge_amount', '30000.00');

    $payment = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account['id']}/payments", [
            'amount_paid' => 10000,
            'payment_method' => 'cash',
        ])
        ->assertCreated()
        ->json('data');

    expect((float) $payment['remaining_balance'])->toBe(20000.0);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/cash-patients/{$account['id']}/balance")
        ->assertOk()
        ->assertJsonPath('data.balance', 20000);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/cash-patients')
        ->assertOk()
        ->assertJsonPath('data.0.id', $account['id'])
        ->assertJsonPath('data.0.patient.display_name', 'Neema Billing');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/cash-patients/{$account['id']}")
        ->assertOk()
        ->assertJsonPath('data.account.id', $account['id'])
        ->assertJsonCount(1, 'data.charges')
        ->assertJsonCount(1, 'data.payments');

    $stored = CashBillingAccountModel::query()->findOrFail($account['id']);
    expect((float) $stored->account_balance)->toBe(20000.0);
});

it('determines insurance billing route when active insurance and contract exist', function (): void {
    $user = makeBillingPhaseUser(['billing.routing.read']);
    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();

    PatientInsuranceModel::query()->create([
        'patient_id' => $patient->id,
        'insurance_type' => 'private',
        'insurance_provider' => 'AAR',
        'policy_number' => 'POL-2026-001',
        'member_id' => 'MBR-2026-001',
        'effective_date' => now()->subDay(),
        'expiry_date' => now()->addMonth(),
        'coverage_level' => 'full',
        'status' => 'active',
    ]);

    BillingPayerContractModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'contract_code' => 'AAR-2026-OPD',
        'contract_name' => 'AAR OPD 2026',
        'payer_type' => 'insurance',
        'payer_name' => 'AAR',
        'currency_code' => 'TZS',
        'default_coverage_percent' => 80,
        'requires_pre_authorization' => false,
        'effective_from' => now()->subDay(),
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/billing-routing/determine', [
            'patient_id' => $patient->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.routing_decision', 'insurance')
        ->assertJsonPath('data.payer_type', 'insurance')
        ->assertJsonPath('data.payer_name', 'AAR');
});

it('creates and applies a billing discount policy through loaded routes', function (): void {
    $user = makeBillingPhaseUser([
        'billing.discounts.read',
        'billing.discounts.manage',
    ]);

    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();
    $invoice = makeBillingPhaseInvoice($patient->id, [
        'status' => 'issued',
    ]);

    $policy = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/discount-policies', [
            'code' => 'VIP-10',
            'name' => 'VIP 10 Percent',
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
            'status' => 'active',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/discount-policies?status=active&availability=all&q=VIP')
        ->assertOk()
        ->assertJsonPath('data.0.id', $policy['id'])
        ->assertJsonPath('data.0.code', 'VIP-10');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/discount-applications', [
            'invoice_number' => $invoice->invoice_number,
            'discount_policy_id' => $policy['id'],
            'reason' => 'VIP agreement',
        ])
        ->assertCreated()
        ->assertJsonPath('data.discount_applied', 10000)
        ->assertJsonPath('data.new_total', 90000)
        ->assertJsonPath('data.financePosting.infrastructure.revenueRecognitionReady', true)
        ->assertJsonPath('data.financePosting.infrastructure.glPostingReady', true)
        ->assertJsonPath('data.financePosting.recognition.status', 'recognized')
        ->assertJsonPath('data.financePosting.recognition.netRevenue', 90000);

    $invoice->refresh();
    expect((float) $invoice->total_amount)->toBe(90000.0);
    expect((float) $invoice->discount_amount)->toBe(10000.0);

    $recognition = RevenueRecognitionModel::query()
        ->where('billing_invoice_id', $invoice->id)
        ->first();

    expect($recognition)->not->toBeNull();
    expect((float) $recognition->net_revenue)->toBe(90000.0);
    expect(
        GLJournalEntryModel::query()
            ->where('reference_type', 'revenue_recognition')
            ->where('reference_id', $invoice->id)
            ->where('tenant_id', $scope['tenantId'])
            ->where('facility_id', $scope['facilityId'])
            ->count()
    )->toBe(2);
});

it('creates approves and processes refunds through loaded routes', function (): void {
    $user = makeBillingPhaseUser([
        'billing.refunds.read',
        'billing.refunds.create',
        'billing.refunds.approve',
        'billing.refunds.process',
    ]);

    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();
    $invoice = makeBillingPhaseInvoice($patient->id, [
        'status' => 'partially_paid',
        'paid_amount' => 50000,
        'balance_amount' => 50000,
    ]);

    $payment = BillingInvoicePaymentModel::query()->create([
        'billing_invoice_id' => $invoice->id,
        'payment_at' => now()->toDateTimeString(),
        'amount' => 50000,
        'cumulative_paid_amount' => 50000,
        'payer_type' => 'self_pay',
        'payment_method' => 'cash',
        'payment_reference' => 'CASH-001',
        'recorded_by_user_id' => $user->id,
        'entry_type' => 'payment',
        'source_action' => 'test.seed',
        'note' => 'Seed payment',
    ]);

    $refund = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/billing-refunds', [
            'invoice_id' => $invoice->id,
            'payment_id' => $payment->id,
            'refund_reason' => 'overpayment',
            'refund_amount' => 10000,
            'refund_method' => 'cash',
        ])
        ->assertCreated()
        ->json('data');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing-refunds?status=pending')
        ->assertOk()
        ->assertJsonPath('data.0.id', $refund['id'])
        ->assertJsonPath('data.0.invoice.invoice_number', $invoice->invoice_number)
        ->assertJsonPath('data.0.patient.patient_number', $patient->patient_number);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/billing-refunds/{$refund['id']}/approve", [
            'actor_name' => 'Finance approver',
        ])
        ->assertOk()
        ->assertJsonPath('data.refund_status', 'approved');

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing-refunds?status=approved')
        ->assertOk()
        ->assertJsonPath('data.0.id', $refund['id']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/billing-refunds/{$refund['id']}/process", [
            'actor_name' => 'Cash office',
        ])
        ->assertOk()
        ->assertJsonPath('data.refund_status', 'processed')
        ->assertJsonPath('data.financePosting.infrastructure.glPostingReady', true)
        ->assertJsonPath('data.financePosting.payoutPosted', true)
        ->assertJsonPath('data.financePosting.ledger.postedCount', 2);

    expect(
        GLJournalEntryModel::query()
            ->where('reference_type', 'refund')
            ->where('reference_id', $refund['id'])
            ->where('tenant_id', $scope['tenantId'])
            ->where('facility_id', $scope['facilityId'])
            ->where('status', 'posted')
            ->count()
    )->toBe(2);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson('/api/v1/billing-refunds?status=processed')
        ->assertOk()
        ->assertJsonPath('data.0.id', $refund['id'])
        ->assertJsonPath('data.0.financePosting.infrastructure.glPostingReady', true)
        ->assertJsonPath('data.0.financePosting.payoutPosted', true)
        ->assertJsonPath('data.0.financePosting.ledger.postedCount', 2);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->getJson("/api/v1/billing-invoices/{$invoice->id}/refunds")
        ->assertOk()
        ->assertJsonPath('data.0.id', $refund['id'])
        ->assertJsonPath('data.0.financePosting.payoutPosted', true);
});

it('denies cash billing account creation without permission', function (): void {
    $user = makeBillingPhaseUser();
    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/cash-patients', [
            'patient_id' => $patient->id,
            'currency_code' => 'TZS',
        ])
        ->assertForbidden();
});

it('denies billing route determination without permission', function (): void {
    $user = makeBillingPhaseUser();
    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/billing-routing/determine', [
            'patient_id' => $patient->id,
        ])
        ->assertForbidden();
});

it('denies discount policy creation without permission', function (): void {
    $user = makeBillingPhaseUser();
    $scope = seedBillingPhaseScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/discount-policies', [
            'code' => 'NO-ACCESS',
            'name' => 'Blocked Policy',
            'discount_type' => 'percentage',
            'discount_percentage' => 10,
        ])
        ->assertForbidden();
});

it('denies refund creation without permission', function (): void {
    $user = makeBillingPhaseUser();
    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();
    $invoice = makeBillingPhaseInvoice($patient->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/billing-refunds', [
            'invoice_id' => $invoice->id,
            'refund_reason' => 'overpayment',
            'refund_amount' => 10000,
            'refund_method' => 'cash',
        ])
        ->assertForbidden();
});

it('validates required cash billing account payload fields', function (): void {
    $user = makeBillingPhaseUser(['billing.cash-accounts.manage']);
    $scope = seedBillingPhaseScope($user->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/cash-patients', [
            'currency_code' => 'TZS',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['patient_id']);
});

it('validates required refund payload fields', function (): void {
    $user = makeBillingPhaseUser(['billing.refunds.create']);
    $scope = seedBillingPhaseScope($user->id);
    $patient = makeBillingPhasePatient();
    $invoice = makeBillingPhaseInvoice($patient->id);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson('/api/v1/billing-refunds', [
            'invoice_id' => $invoice->id,
            'refund_reason' => 'overpayment',
            'refund_method' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['refund_amount']);
});
