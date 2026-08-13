<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingChargeModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingPaymentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeVoidRefundUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function seedVoidRefundScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-VRF',
        'name' => 'Void Refund Test Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-VRF',
        'name' => 'Dar Void Refund Centre',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $userId,
        'role' => 'cashier',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'tenantId' => $tenantId,
        'facilityId' => $facilityId,
        'headers' => [
            'X-Tenant-Code' => 'TZ-VRF',
            'X-Facility-Code' => 'DAR-VRF',
        ],
    ];
}

function makeVoidRefundPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-VRF-'.strtoupper(Str::random(8)),
        'first_name' => 'VoidRefund',
        'last_name' => 'TestPatient',
        'gender' => 'female',
        'date_of_birth' => '1990-01-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function createVoidRefundAccount(array $scope, User $user): CashBillingAccountModel
{
    $account = CashBillingAccountModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'patient_id' => makeVoidRefundPatient()->id,
        'currency_code' => 'TZS',
        'account_balance' => 40000,
        'total_charged' => 100000,
        'total_paid' => 60000,
        'status' => 'active',
        'notes' => 'Legacy account for void/refund test',
    ]);

    CashBillingChargeModel::query()->create([
        'cash_billing_account_id' => $account->id,
        'service_name' => 'Consultation',
        'quantity' => 1,
        'unit_price' => 50000,
        'charge_amount' => 50000,
        'recorded_by_user_id' => $user->id,
        'charge_date' => now()->subDays(5)->toDateTimeString(),
        'description' => 'Emergency consultation',
    ]);

    CashBillingChargeModel::query()->create([
        'cash_billing_account_id' => $account->id,
        'service_name' => 'Lab Test',
        'quantity' => 2,
        'unit_price' => 25000,
        'charge_amount' => 50000,
        'recorded_by_user_id' => $user->id,
        'charge_date' => now()->subDays(3)->toDateTimeString(),
        'description' => 'Blood work',
    ]);

    CashBillingPaymentModel::query()->create([
        'cash_billing_account_id' => $account->id,
        'amount_paid' => 40000,
        'currency_code' => 'TZS',
        'payment_method' => 'cash',
        'paid_at' => now()->subDays(2)->toDateTimeString(),
        'confirmed_by_user_id' => $user->id,
    ]);

    CashBillingPaymentModel::query()->create([
        'cash_billing_account_id' => $account->id,
        'amount_paid' => 20000,
        'currency_code' => 'TZS',
        'payment_method' => 'mobile_money',
        'paid_at' => now()->subDay()->toDateTimeString(),
        'confirmed_by_user_id' => $user->id,
    ]);

    return $account;
}

// --- Void Tests ---

it('voids an active cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/void", [
            'void_reason' => 'Patient requested cancellation of all services',
        ])
        ->assertOk()
        ->json('data');

    expect($response['status'])->toBe('voided');
    expect($response['notes'])->toContain('Account voided');
    expect($response['notes'])->toContain('Patient requested cancellation');

    $account->refresh();
    expect($account->status)->toBe('voided');
});

it('rejects void of non-existent cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage']);
    $scope = seedVoidRefundScope($user->id);
    $unknownId = (string) Str::uuid();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$unknownId}/void", [
            'void_reason' => 'Test void',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_VOID_FAILED']);
});

it('rejects void of already-converted cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $account->update(['status' => 'converted']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/void", [
            'void_reason' => 'Test void',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_VOID_FAILED']);
});

it('rejects void of already-voided cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $account->update(['status' => 'voided']);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/void", [
            'void_reason' => 'Test void',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_VOID_FAILED']);
});

it('requires void_reason to void an account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/void", [])
        ->assertStatus(422);
});

// --- Refund Tests ---

it('refunds a payment on an active cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $payment = $account->payments()->where('amount_paid', 40000)->first();

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => $payment->id,
            'refund_amount' => 15000,
            'refund_reason' => 'Partial refund for consultation',
        ])
        ->assertOk()
        ->json('data');

    expect((float) $response['payment']['refunded_amount'])->toBe(15000.0);
    expect($response['payment']['refund_reason'])->toBe('Partial refund for consultation');
    expect((float) $response['account']['total_paid'])->toBe(45000.0);
    expect((float) $response['account']['account_balance'])->toBe(55000.0);

    $payment->refresh();
    expect((float) $payment->refunded_amount)->toBe(15000.0);
});

it('rejects refund of non-existent payment', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => (string) Str::uuid(),
            'refund_amount' => 5000,
            'refund_reason' => 'Test refund',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_REFUND_FAILED']);
});

it('rejects refund that exceeds payment available balance', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $payment = $account->payments()->where('amount_paid', 20000)->first();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => $payment->id,
            'refund_amount' => 999999,
            'refund_reason' => 'Exceeds payment',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_REFUND_FAILED']);
});

it('rejects refund on a converted cash billing account', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $account->update(['status' => 'converted']);

    $payment = $account->payments()->first();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => $payment->id,
            'refund_amount' => 5000,
            'refund_reason' => 'Test refund on converted',
        ])
        ->assertStatus(422)
        ->assertJson(['code' => 'CASH_BILLING_REFUND_FAILED']);
});

it('handles multiple partial refunds on the same payment', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage', 'billing.cash-accounts.read']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $payment = $account->payments()->where('amount_paid', 40000)->first();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => $payment->id,
            'refund_amount' => 10000,
            'refund_reason' => 'First partial refund',
        ])
        ->assertOk();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [
            'payment_id' => $payment->id,
            'refund_amount' => 5000,
            'refund_reason' => 'Second partial refund',
        ])
        ->assertOk();

    $payment->refresh();
    expect((float) $payment->refunded_amount)->toBe(15000.0);
});

it('requires payment_id and refund_amount for refund', function (): void {
    $user = makeVoidRefundUser(['billing.cash-accounts.manage']);
    $scope = seedVoidRefundScope($user->id);
    $account = createVoidRefundAccount($scope, $user);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/refund", [])
        ->assertStatus(422);
});
