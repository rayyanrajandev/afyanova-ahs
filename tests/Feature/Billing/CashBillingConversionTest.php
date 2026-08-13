<?php

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\BillingInvoiceModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingChargeModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingPaymentModel;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeCashBillingUser(array $permissions = []): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }

    return $user;
}

function seedCashBillingScope(int $userId): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TZ-CVT',
        'name' => 'Conversion Test Network',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'DAR-CVT',
        'name' => 'Dar Conversion Centre',
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
            'X-Tenant-Code' => 'TZ-CVT',
            'X-Facility-Code' => 'DAR-CVT',
        ],
    ];
}

function makeCashBillingPatient(): PatientModel
{
    return PatientModel::query()->create([
        'patient_number' => 'PT-CVT-'.strtoupper(Str::random(8)),
        'first_name' => 'Conversion',
        'last_name' => 'TestPatient',
        'gender' => 'female',
        'date_of_birth' => '1990-01-15',
        'country_code' => 'TZ',
        'status' => 'active',
    ]);
}

function createCashBillingAccountWithCharges(string $patientId, array $scope, User $user): CashBillingAccountModel
{
    $account = CashBillingAccountModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'patient_id' => $patientId,
        'currency_code' => 'TZS',
        'account_balance' => 70000,
        'total_charged' => 100000,
        'total_paid' => 30000,
        'status' => 'active',
        'notes' => 'Legacy account for conversion test',
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
        'amount_paid' => 20000,
        'currency_code' => 'TZS',
        'payment_method' => 'cash',
        'paid_at' => now()->subDays(2)->toDateTimeString(),
        'confirmed_by_user_id' => $user->id,
    ]);

    CashBillingPaymentModel::query()->create([
        'cash_billing_account_id' => $account->id,
        'amount_paid' => 10000,
        'currency_code' => 'TZS',
        'payment_method' => 'mobile_money',
        'paid_at' => now()->subDay()->toDateTimeString(),
        'confirmed_by_user_id' => $user->id,
    ]);

    return $account;
}

it('converts a cash billing account to a billing invoice', function (): void {
    $user = makeCashBillingUser([
        'billing.cash-accounts.read',
        'billing.cash-accounts.manage',
        'billing.invoices.create',
        'billing.invoices.read',
    ]);
    $scope = seedCashBillingScope($user->id);
    $patient = makeCashBillingPatient();
    $account = createCashBillingAccountWithCharges($patient->id, $scope, $user);

    $response = $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/convert-to-invoice")
        ->assertCreated()
        ->json('data');

    expect($response['message'])->toContain('converted to invoice');
    expect($response['invoice']['invoice_number'])->not->toBeEmpty();
    expect((float) $response['invoice']['total_amount'])->toBe(100000.0);
    expect((float) $response['invoice']['paid_amount'])->toBe(30000.0);
    expect((float) $response['invoice']['balance_amount'])->toBe(70000.0);
    expect($response['invoice']['status'])->toBe('draft');

    $account->refresh();
    expect($account->status)->toBe('converted');
});

it('rejects conversion of non-existent cash billing account', function (): void {
    $user = makeCashBillingUser(['billing.cash-accounts.manage']);
    $scope = seedCashBillingScope($user->id);
    $unknownId = (string) Str::uuid();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$unknownId}/convert-to-invoice")
        ->assertStatus(500);
});

it('rejects conversion of already-converted cash billing account', function (): void {
    $user = makeCashBillingUser([
        'billing.cash-accounts.manage',
        'billing.cash-accounts.read',
    ]);
    $scope = seedCashBillingScope($user->id);
    $patient = makeCashBillingPatient();
    $account = createCashBillingAccountWithCharges($patient->id, $scope, $user);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/convert-to-invoice")
        ->assertCreated();

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/convert-to-invoice")
        ->assertStatus(500);
});

it('rejects conversion of empty cash billing account with no charges', function (): void {
    $user = makeCashBillingUser(['billing.cash-accounts.manage']);
    $scope = seedCashBillingScope($user->id);
    $patient = makeCashBillingPatient();

    $account = CashBillingAccountModel::query()->create([
        'tenant_id' => $scope['tenantId'],
        'facility_id' => $scope['facilityId'],
        'patient_id' => $patient->id,
        'currency_code' => 'TZS',
        'account_balance' => 0,
        'total_charged' => 0,
        'total_paid' => 0,
        'status' => 'active',
    ]);

    $this->actingAs($user)
        ->withHeaders($scope['headers'])
        ->postJson("/api/v1/cash-patients/{$account->id}/convert-to-invoice")
        ->assertStatus(500);
});
