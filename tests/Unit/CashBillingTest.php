<?php

namespace Tests\Unit\Billing;

use App\Models\User;
use App\Modules\Billing\Infrastructure\Models\CashBillingAccountModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingChargeModel;
use App\Modules\Billing\Infrastructure\Models\CashBillingPaymentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_cash_billing_account(): void
    {
        $account = CashBillingAccountModel::create([
            'tenant_id' => (string) Str::uuid(),
            'facility_id' => (string) Str::uuid(),
            'patient_id' => (string) Str::uuid(),
            'currency_code' => 'TZS',
            'account_balance' => 0,
            'total_charged' => 0,
            'total_paid' => 0,
            'status' => 'active',
        ]);

        $this->assertNotNull($account->id);
        $this->assertEquals('active', $account->status);
        $this->assertEquals(0, $account->account_balance);
    }

    public function test_record_cash_charge(): void
    {
        $user = User::factory()->create();

        $account = CashBillingAccountModel::create([
            'tenant_id' => (string) Str::uuid(),
            'facility_id' => (string) Str::uuid(),
            'patient_id' => (string) Str::uuid(),
            'currency_code' => 'TZS',
            'account_balance' => 0,
            'total_charged' => 0,
            'total_paid' => 0,
            'status' => 'active',
        ]);

        $charge = CashBillingChargeModel::create([
            'cash_billing_account_id' => $account->id,
            'service_name' => 'Consultation',
            'quantity' => 1,
            'unit_price' => 50000,
            'charge_amount' => 50000,
            'recorded_by_user_id' => $user->id,
            'charge_date' => now(),
        ]);

        $this->assertNotNull($charge->id);
        $this->assertEquals(50000, $charge->charge_amount);
        $this->assertEquals('Consultation', $charge->service_name);
    }

    public function test_record_cash_payment(): void
    {
        $user = User::factory()->create();

        $account = CashBillingAccountModel::create([
            'tenant_id' => (string) Str::uuid(),
            'facility_id' => (string) Str::uuid(),
            'patient_id' => (string) Str::uuid(),
            'currency_code' => 'TZS',
            'account_balance' => 100000,
            'total_charged' => 100000,
            'total_paid' => 0,
            'status' => 'active',
        ]);

        $payment = CashBillingPaymentModel::create([
            'cash_billing_account_id' => $account->id,
            'amount_paid' => 60000,
            'currency_code' => 'TZS',
            'payment_method' => 'cash',
            'paid_at' => now(),
            'confirmed_by_user_id' => $user->id,
            'receipt_number' => 'RCP-' . uniqid(),
        ]);

        $this->assertNotNull($payment->id);
        $this->assertEquals(60000, $payment->amount_paid);
        $this->assertEquals('cash', $payment->payment_method);
    }

    public function test_payment_methods_supported(): void
    {
        $user = User::factory()->create();
        $methods = ['cash', 'card', 'mobile_money', 'check'];

        foreach ($methods as $method) {
            $payment = CashBillingPaymentModel::create([
                'cash_billing_account_id' => CashBillingAccountModel::create([
                    'tenant_id' => (string) Str::uuid(),
                    'facility_id' => (string) Str::uuid(),
                    'patient_id' => (string) Str::uuid(),
                    'currency_code' => 'TZS',
                    'account_balance' => 50000,
                    'total_charged' => 50000,
                    'total_paid' => 0,
                    'status' => 'active',
                ])->id,
                'amount_paid' => 50000,
                'currency_code' => 'TZS',
                'payment_method' => $method,
                'paid_at' => now(),
                'confirmed_by_user_id' => $user->id,
                'receipt_number' => 'RCP-' . uniqid(),
            ]);

            $this->assertEquals($method, $payment->payment_method);
        }
    }
}
