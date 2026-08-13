<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ENTITLEMENT_KEY = 'clinical.walk_in_queue';

    public function up(): void
    {
        if (! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        DB::table('platform_subscription_plan_entitlements')
            ->where('entitlement_key', self::ENTITLEMENT_KEY)
            ->update([
                'entitlement_label' => 'Direct service request queue',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_subscription_plan_entitlements')) {
            return;
        }

        DB::table('platform_subscription_plan_entitlements')
            ->where('entitlement_key', self::ENTITLEMENT_KEY)
            ->update([
                'entitlement_label' => 'Walk-in service request queue',
                'updated_at' => now(),
            ]);
    }
};
