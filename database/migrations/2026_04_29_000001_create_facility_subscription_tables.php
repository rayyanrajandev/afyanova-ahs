<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('billing_cycle', 20)->default('monthly');
            $table->decimal('price_amount', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('TZS');
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'sort_order'], 'platform_subscription_plans_status_sort_idx');
        });

        Schema::create('platform_subscription_plan_entitlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id');
            $table->string('entitlement_key', 120);
            $table->string('entitlement_label', 160);
            $table->string('entitlement_group', 80)->nullable();
            $table->string('entitlement_type', 30)->default('feature');
            $table->unsignedInteger('limit_value')->nullable();
            $table->boolean('enabled')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'entitlement_key'], 'platform_subscription_plan_entitlements_plan_key_unique');
            $table->index(['entitlement_key', 'enabled'], 'platform_subscription_plan_entitlements_key_enabled_idx');

            $table->foreign('plan_id')
                ->references('id')
                ->on('platform_subscription_plans')
                ->cascadeOnDelete();
        });

        Schema::create('facility_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('plan_id')->nullable();
            $table->string('status', 30)->default('trial');
            $table->string('billing_cycle', 20)->default('monthly');
            $table->decimal('price_amount', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('TZS');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_starts_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('next_invoice_at')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancellation_effective_at')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('facility_id', 'facility_subscriptions_facility_unique');
            $table->index(['tenant_id', 'status'], 'facility_subscriptions_tenant_status_idx');
            $table->index(['plan_id', 'status'], 'facility_subscriptions_plan_status_idx');

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();
            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->cascadeOnDelete();
            $table->foreign('plan_id')
                ->references('id')
                ->on('platform_subscription_plans')
                ->nullOnDelete();
        });

        Schema::create('facility_subscription_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('facility_subscription_id')->nullable();
            $table->uuid('facility_id');
            $table->foreignId('actor_id')->nullable();
            $table->string('action', 120);
            $table->json('changes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['facility_id', 'created_at'], 'facility_subscription_audit_logs_facility_created_idx');
            $table->index(['action', 'created_at'], 'facility_subscription_audit_logs_action_created_idx');

            $table->foreign('facility_subscription_id')
                ->references('id')
                ->on('facility_subscriptions')
                ->nullOnDelete();
            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->cascadeOnDelete();
            $table->foreign('actor_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        $this->seedDefaultPlans();
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_subscription_audit_logs');
        Schema::dropIfExists('facility_subscriptions');
        Schema::dropIfExists('platform_subscription_plan_entitlements');
        Schema::dropIfExists('platform_subscription_plans');
    }

    private function seedDefaultPlans(): void
    {
        $now = now();
        $plans = [
            [
                'code' => 'patient_registration',
                'name' => 'Patient Access Starter',
                'description' => 'Entry plan for registration, patient search, demographics, and facility administration.',
                'price_amount' => '75000.00',
                'sort_order' => 10,
                'entitlements' => [
                    ['patients.registration', 'Patient registration', 'Patient Access'],
                    ['patients.search', 'Patient search', 'Patient Access'],
                    ['patients.demographics', 'Demographic maintenance', 'Patient Access'],
                    ['platform.facility_admin', 'Facility administration', 'Platform'],
                ],
            ],
            [
                'code' => 'front_desk_billing',
                'name' => 'Front Office Essentials',
                'description' => 'Patient access plus appointments, cashier billing, receipts, and daily cash reporting.',
                'price_amount' => '175000.00',
                'sort_order' => 20,
                'entitlements' => [
                    ['patients.registration', 'Patient registration', 'Patient Access'],
                    ['appointments.scheduling', 'Appointment scheduling', 'Front Office'],
                    ['billing.cashier', 'Cashier billing', 'Revenue Cycle'],
                    ['billing.receipts', 'Receipts and payment history', 'Revenue Cycle'],
                    ['reports.daily_cash', 'Daily cash reports', 'Reporting'],
                ],
            ],
            [
                'code' => 'clinical_operations',
                'name' => 'Clinical Operations Plus',
                'description' => 'Front office and billing plus clinical encounters, orders, pharmacy, laboratory, and stock issue workflows.',
                'price_amount' => '450000.00',
                'sort_order' => 30,
                'entitlements' => [
                    ['patients.registration', 'Patient registration', 'Patient Access'],
                    ['appointments.scheduling', 'Appointment scheduling', 'Front Office'],
                    ['clinical.encounters', 'Clinical encounters', 'Clinical'],
                    ['clinical.orders', 'Clinical order entry', 'Clinical'],
                    ['laboratory.orders', 'Laboratory orders', 'Clinical'],
                    ['pharmacy.dispensing', 'Pharmacy dispensing', 'Clinical'],
                    ['inventory.stock_issue', 'Clinical stock issue', 'Inventory'],
                    ['reports.operational', 'Operational reports', 'Reporting'],
                ],
            ],
            [
                'code' => 'hospital_network',
                'name' => 'Enterprise Hospital Network',
                'description' => 'Full hospital operations with network controls, cross-facility reporting, integrations, and advanced audit access.',
                'price_amount' => '900000.00',
                'sort_order' => 40,
                'entitlements' => [
                    ['patients.registration', 'Patient registration', 'Patient Access'],
                    ['clinical.encounters', 'Clinical encounters', 'Clinical'],
                    ['billing.revenue_cycle', 'Revenue cycle management', 'Revenue Cycle'],
                    ['inventory.procurement', 'Inventory and procurement', 'Inventory'],
                    ['multi_facility.operations', 'Multi-facility operations', 'Platform'],
                    ['audit.advanced', 'Advanced audit and export', 'Governance'],
                    ['integrations.interoperability', 'Integration adapters', 'Interoperability'],
                    ['reports.executive', 'Executive reporting', 'Reporting'],
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $planId = (string) Str::uuid();

            DB::table('platform_subscription_plans')->insert([
                'id' => $planId,
                'code' => $plan['code'],
                'name' => $plan['name'],
                'description' => $plan['description'],
                'billing_cycle' => 'monthly',
                'price_amount' => $plan['price_amount'],
                'currency_code' => 'TZS',
                'status' => 'active',
                'sort_order' => $plan['sort_order'],
                'metadata' => json_encode([
                    'pricing_policy' => 'Starter monthly testing fee. Edit before final commercial billing.',
                    'requires_price_configuration' => false,
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($plan['entitlements'] as [$key, $label, $group]) {
                DB::table('platform_subscription_plan_entitlements')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $planId,
                    'entitlement_key' => $key,
                    'entitlement_label' => $label,
                    'entitlement_group' => $group,
                    'entitlement_type' => 'feature',
                    'limit_value' => null,
                    'enabled' => true,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
