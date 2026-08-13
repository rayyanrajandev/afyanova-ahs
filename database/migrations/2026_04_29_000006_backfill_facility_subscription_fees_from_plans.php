<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facility_subscriptions') || ! Schema::hasTable('platform_subscription_plans')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE facility_subscriptions AS subscription
            SET
                price_amount = plan.price_amount,
                currency_code = plan.currency_code,
                billing_cycle = plan.billing_cycle,
                updated_at = CURRENT_TIMESTAMP
            FROM platform_subscription_plans AS plan
            WHERE subscription.plan_id = plan.id
                AND subscription.price_amount = 0
                AND plan.price_amount > 0
        SQL);
    }

    public function down(): void
    {
        // Intentional no-op: subscription fees may have been manually corrected after this backfill.
    }
};
