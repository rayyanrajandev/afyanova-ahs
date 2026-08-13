<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_engine_shadow_diffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_kind', 60);
            $table->string('source_id', 100);
            $table->uuid('chargeable_item_id')->nullable();
            $table->string('legacy_service_code', 100)->nullable();
            $table->decimal('legacy_unit_price', 14, 2)->nullable();
            $table->char('legacy_currency_code', 3)->nullable();
            $table->string('legacy_pricing_status', 30)->nullable();
            $table->decimal('new_unit_price', 14, 2)->nullable();
            $table->char('new_currency_code', 3)->nullable();
            $table->string('new_pricing_status', 30)->nullable();
            $table->boolean('matched');
            $table->string('mismatch_reason', 60)->nullable();
            $table->timestamp('created_at');

            $table->index(['source_kind', 'source_id', 'created_at'], 'pricing_engine_shadow_diffs_source_created_idx');
            $table->index(['matched', 'created_at'], 'pricing_engine_shadow_diffs_matched_created_idx');
            $table->index('chargeable_item_id', 'pricing_engine_shadow_diffs_chargeable_item_id_idx');

            // Deliberately no FK to chargeable_items: an order's catalog FK
            // referencing an id that has no chargeable_items row yet is
            // exactly the "legacy_priced_new_missing" case this table
            // exists to catch (Phase 1 backfill hasn't run for that item,
            // or Phase 3 hasn't reached this domain yet). A hard FK would
            // make logging that real, useful signal throw instead.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_engine_shadow_diffs');
    }
};
