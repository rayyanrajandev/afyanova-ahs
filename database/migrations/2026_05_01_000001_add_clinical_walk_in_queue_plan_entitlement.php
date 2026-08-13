<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const MIGRATION_TAG = '2026_05_01_000001_add_clinical_walk_in_queue_plan_entitlement';

    private const ENTITLEMENT_KEY = 'clinical.walk_in_queue';

    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plans')
            || ! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $now = now();

        /** @var list<string> */
        $planIds = DB::table('platform_subscription_plans')->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();

        $metadata = [
                'catalog_profile' => 'DIRECT_SERVICE_REQUEST_QUEUE',
            'seeded_by' => self::MIGRATION_TAG,
            'route_permissions' => [
                'service.requests.read',
                'service.requests.create',
                'service.requests.update-status',
                'service.requests.export',
                'service.requests.audit-logs.read',
            ],
        ];

        foreach ($planIds as $planId) {
            $existing = DB::table('platform_subscription_plan_entitlements')
                ->where('plan_id', $planId)
                ->where('entitlement_key', self::ENTITLEMENT_KEY)
                ->first(['id']);

            $payload = [
                'entitlement_label' => 'Direct service request queue',
                'entitlement_group' => 'Front Office',
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
        if (! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        $needle = sprintf('"seeded_by":"%s"', self::MIGRATION_TAG);

        DB::table('platform_subscription_plan_entitlements')
            ->where('entitlement_key', self::ENTITLEMENT_KEY)
            ->where('metadata', 'like', '%'.$needle.'%')
            ->delete();
    }
};
