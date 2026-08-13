<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PricingEngine_Migration_Plan.md Phase 5: payer-contract price overrides
 * and manual invoice auto-pricing are migrating off billing_service_catalog_items
 * onto chargeable_items, and both need tax classification -- the old
 * catalog carried tax_rate_percent/is_taxable per item, chargeable_items
 * never needed it until now. Additive only, matches every other Phase 5
 * schema change in this migration set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeable_items', function (Blueprint $table): void {
            $table->decimal('tax_rate_percent', 5, 2)->nullable()->after('status_reason');
            $table->boolean('is_taxable')->default(false)->after('tax_rate_percent');
        });
    }

    public function down(): void
    {
        Schema::table('chargeable_items', function (Blueprint $table): void {
            $table->dropColumn(['tax_rate_percent', 'is_taxable']);
        });
    }
};
