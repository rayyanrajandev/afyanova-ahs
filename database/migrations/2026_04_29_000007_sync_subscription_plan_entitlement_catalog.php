<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const MIGRATION_TAG = '2026_04_29_000007_sync_subscription_plan_entitlement_catalog';

    /**
     * @var array<int, array{key: string, label: string, group: string}>
     */
    private array $catalog = [
        ['key' => 'patients.registration', 'label' => 'Patient registration', 'group' => 'Patient Access'],
        ['key' => 'patients.search', 'label' => 'Patient search', 'group' => 'Patient Access'],
        ['key' => 'patients.demographics', 'label' => 'Demographic maintenance', 'group' => 'Patient Access'],
        ['key' => 'appointments.scheduling', 'label' => 'Appointment scheduling', 'group' => 'Front Office'],
        ['key' => 'billing.cashier', 'label' => 'Cashier billing', 'group' => 'Revenue Cycle'],
        ['key' => 'billing.receipts', 'label' => 'Receipts and payment history', 'group' => 'Revenue Cycle'],
        ['key' => 'billing.revenue_cycle', 'label' => 'Revenue cycle management', 'group' => 'Revenue Cycle'],
        ['key' => 'clinical.encounters', 'label' => 'Clinical encounters', 'group' => 'Clinical'],
        ['key' => 'clinical.orders', 'label' => 'Clinical order entry', 'group' => 'Clinical'],
        ['key' => 'laboratory.orders', 'label' => 'Laboratory orders', 'group' => 'Clinical'],
        ['key' => 'pharmacy.dispensing', 'label' => 'Pharmacy dispensing', 'group' => 'Clinical'],
        ['key' => 'inventory.stock_issue', 'label' => 'Clinical stock issue', 'group' => 'Inventory'],
        ['key' => 'inventory.procurement', 'label' => 'Inventory and procurement', 'group' => 'Inventory'],
        ['key' => 'platform.facility_admin', 'label' => 'Facility administration', 'group' => 'Platform'],
        ['key' => 'multi_facility.operations', 'label' => 'Multi-facility operations', 'group' => 'Platform'],
        ['key' => 'audit.advanced', 'label' => 'Advanced audit and export', 'group' => 'Governance'],
        ['key' => 'integrations.interoperability', 'label' => 'Integration adapters', 'group' => 'Interoperability'],
        ['key' => 'reports.daily_cash', 'label' => 'Daily cash reports', 'group' => 'Reporting'],
        ['key' => 'reports.operational', 'label' => 'Operational reports', 'group' => 'Reporting'],
        ['key' => 'reports.executive', 'label' => 'Executive reporting', 'group' => 'Reporting'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plans') || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $now = now();
        $plans = DB::table('platform_subscription_plans')->get(['id']);

        foreach ($plans as $plan) {
            foreach ($this->catalog as $entitlement) {
                $exists = DB::table('platform_subscription_plan_entitlements')
                    ->where('plan_id', $plan->id)
                    ->where('entitlement_key', $entitlement['key'])
                    ->exists();

                if ($exists) {
                    DB::table('platform_subscription_plan_entitlements')
                        ->where('plan_id', $plan->id)
                        ->where('entitlement_key', $entitlement['key'])
                        ->update([
                            'entitlement_label' => $entitlement['label'],
                            'entitlement_group' => $entitlement['group'],
                            'updated_at' => $now,
                        ]);

                    continue;
                }

                DB::table('platform_subscription_plan_entitlements')->insert([
                    'id' => (string) Str::uuid(),
                    'plan_id' => $plan->id,
                    'entitlement_key' => $entitlement['key'],
                    'entitlement_label' => $entitlement['label'],
                    'entitlement_group' => $entitlement['group'],
                    'entitlement_type' => 'feature',
                    'limit_value' => null,
                    'enabled' => false,
                    'metadata' => json_encode(['seeded_by' => self::MIGRATION_TAG], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        DB::table('platform_subscription_plan_entitlements')
            ->whereIn('entitlement_key', array_column($this->catalog, 'key'))
            ->where('metadata', json_encode(['seeded_by' => self::MIGRATION_TAG], JSON_THROW_ON_ERROR))
            ->delete();
    }
};
