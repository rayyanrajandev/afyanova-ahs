<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const MIGRATION_TAG = '2026_07_23_000001_add_clinical_procedure_orders_plan_entitlement';

    private const ENTITLEMENT_KEY = 'clinical_procedure.orders';

    private const PERMISSIONS = [
        'clinical_procedure.orders.read',
        'clinical_procedure.orders.create',
        'clinical_procedure.orders.update',
        'clinical_procedure.orders.update-status',
        'clinical_procedure.orders.view-audit-logs',
    ];

    public function up(): void
    {
        $this->seedPermissions();

        if (! Schema::hasTable('platform_subscription_plans')
            || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $now = now();

        /** @var list<string> */
        $planIds = DB::table('platform_subscription_plans')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $metadata = [
            'catalog_profile' => 'CLINICAL_PROCEDURE_ORDERS',
            'seeded_by' => self::MIGRATION_TAG,
            'route_permissions' => self::PERMISSIONS,
        ];

        foreach ($planIds as $planId) {
            $existing = DB::table('platform_subscription_plan_entitlements')
                ->where('plan_id', $planId)
                ->where('entitlement_key', self::ENTITLEMENT_KEY)
                ->first(['id']);

            $payload = [
                'entitlement_label' => 'Clinical procedure orders, worklist, and results',
                'entitlement_group' => 'Care Delivery',
                'entitlement_type' => 'feature',
                'limit_value' => null,
                'enabled' => true,
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('platform_subscription_plan_entitlements')
                    ->where('id', $existing->id)
                    ->update($payload);

                continue;
            }

            DB::table('platform_subscription_plan_entitlements')->insert(array_merge($payload, [
                'id' => (string) Str::uuid(),
                'plan_id' => $planId,
                'entitlement_key' => self::ENTITLEMENT_KEY,
                'created_at' => $now,
            ]));
        }
    }

    public function down(): void
    {
        $needle = sprintf('"seeded_by":"%s"', self::MIGRATION_TAG);

        DB::table('permissions')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        if (! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        DB::table('platform_subscription_plan_entitlements')
            ->where('entitlement_key', self::ENTITLEMENT_KEY)
            ->where('metadata', 'like', '%'.$needle.'%')
            ->delete();
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }
};
