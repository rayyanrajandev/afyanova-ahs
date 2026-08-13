<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plans')) {
            return;
        }

        $plans = [
            'patient_registration' => [
                'name' => 'Patient Access Starter',
                'description' => 'Entry plan for registration, patient search, demographics, and facility administration.',
            ],
            'front_desk_billing' => [
                'name' => 'Front Office Essentials',
                'description' => 'Patient access plus appointments, cashier billing, receipts, and daily cash reporting.',
            ],
            'clinical_operations' => [
                'name' => 'Clinical Operations Plus',
                'description' => 'Front office and billing plus clinical encounters, orders, pharmacy, laboratory, and stock issue workflows.',
            ],
            'hospital_network' => [
                'name' => 'Enterprise Hospital Network',
                'description' => 'Full hospital operations with network controls, cross-facility reporting, integrations, and advanced audit access.',
            ],
        ];

        foreach ($plans as $code => $attributes) {
            DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->update([
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscription_plans')) {
            return;
        }

        $plans = [
            'patient_registration' => [
                'name' => 'Patient Registration Pilot',
                'description' => 'Controlled intake launch for demographic registration, patient search, and facility admin operations.',
            ],
            'front_desk_billing' => [
                'name' => 'Front Desk and Billing',
                'description' => 'Registration plus appointments, cash desk billing, and basic operational reporting.',
            ],
            'clinical_operations' => [
                'name' => 'Clinical Operations',
                'description' => 'Front office and billing plus clinical encounters, orders, pharmacy, laboratory, and stock issue workflows.',
            ],
            'hospital_network' => [
                'name' => 'Hospital Network',
                'description' => 'Full hospital operations with network controls, cross-facility reporting, integrations, and advanced audit access.',
            ],
        ];

        foreach ($plans as $code => $attributes) {
            DB::table('platform_subscription_plans')
                ->where('code', $code)
                ->update([
                    'name' => $attributes['name'],
                    'description' => $attributes['description'],
                    'updated_at' => now(),
                ]);
        }
    }
};
