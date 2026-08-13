<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IV fluid dose instructions were treating the product's concentration (e.g. "0.9%")
 * as if it were the dose, because there was nowhere to record the actual
 * administration volume ("500 mL") separately from that concentration. This gives
 * pure IV fluid catalog items a structured "container size" distinct from strength.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->decimal('container_volume_value', 10, 2)->nullable()->after('route');
            $table->string('container_volume_unit', 20)->nullable()->after('container_volume_value');
        });
    }

    public function down(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->dropColumn(['container_volume_value', 'container_volume_unit']);
        });
    }
};
