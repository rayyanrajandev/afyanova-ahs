<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md, Phase 1. Purely additive: gives
 * Clinical Catalog typed columns for the clinical descriptors that today
 * only exist as duplicated columns on inventory_items (see the
 * Inventory_CreateItem_Architecture_Audit.md field-ownership audit).
 * Nothing reads from these columns yet -- that's Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->string('generic_name', 180)->nullable()->after('name');
            $table->string('dosage_form', 60)->nullable()->after('generic_name');
            $table->string('strength', 60)->nullable()->after('dosage_form');
            $table->string('route', 60)->nullable()->after('strength');
            $table->string('storage_conditions', 60)->nullable()->after('route');
            // Nullable, no default -- these are a tri-state, not a boolean: null means
            // "Clinical Catalog has no opinion yet" (falls back to the inventory row's
            // own value), true/false means the catalog has an actual, enforceable
            // answer. A default(false) here would be indistinguishable from "confirmed
            // not cold-chain" and would silently override real inventory-side data for
            // every catalog item the Phase 1 backfill didn't touch.
            $table->boolean('requires_cold_chain')->nullable()->after('storage_conditions');
            $table->boolean('is_controlled_substance')->nullable()->after('requires_cold_chain');
            $table->string('controlled_substance_schedule', 20)->nullable()->after('is_controlled_substance');

            // Optional, deferred (Item Identity Model, Inventory_MasterData_Alignment_Plan.md):
            // groups strength/form variants of the same drug (e.g. "Paracetamol 500mg"
            // and "Paracetamol 250mg/5ml Syrup") for cross-variant queries, without
            // merging them into one row. Nothing populates or reads it yet.
            $table->string('generic_group_code', 120)->nullable()->after('controlled_substance_schedule');

            $table->index('generic_group_code');
            $table->index('requires_cold_chain');
            $table->index('is_controlled_substance');
        });
    }

    public function down(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->dropIndex(['generic_group_code']);
            $table->dropIndex(['requires_cold_chain']);
            $table->dropIndex(['is_controlled_substance']);
            $table->dropColumn([
                'generic_name',
                'dosage_form',
                'strength',
                'route',
                'storage_conditions',
                'requires_cold_chain',
                'is_controlled_substance',
                'controlled_substance_schedule',
                'generic_group_code',
            ]);
        });
    }
};
