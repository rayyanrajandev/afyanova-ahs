<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private array $monthlyFees = [
        'patient_registration' => '75000.00',
        'front_desk_billing' => '175000.00',
        'clinical_operations' => '450000.00',
        'hospital_network' => '900000.00',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plans')) {
            return;
        }

        foreach ($this->monthlyFees as $code => $fee) {
            $plan = DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->first(['metadata', 'price_amount']);

            if (! $plan || (float) $plan->price_amount > 0) {
                continue;
            }

            DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->update([
                    'price_amount' => $fee,
                    'currency_code' => 'TZS',
                    'billing_cycle' => 'monthly',
                    'metadata' => json_encode($this->configuredMetadata($plan->metadata), JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscription_plans')) {
            return;
        }

        foreach ($this->monthlyFees as $code => $fee) {
            DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->where('price_amount', $fee)
                ->update([
                    'price_amount' => '0.00',
                    'metadata' => json_encode([
                        'pricing_policy' => 'Set the facility-specific fee before live billing.',
                        'requires_price_configuration' => true,
                    ], JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
        }
    }

    private function configuredMetadata(mixed $metadata): array
    {
        $decoded = is_string($metadata) && trim($metadata) !== ''
            ? json_decode($metadata, true)
            : [];

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return array_merge($decoded, [
            'pricing_policy' => 'Starter monthly testing fee. Edit before final commercial billing.',
            'requires_price_configuration' => false,
        ]);
    }
};
