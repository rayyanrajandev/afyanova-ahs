<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distinct from duration_value/duration_unit, which mean total therapy duration
 * ("7 days"). These record how an IV infusion is actually administered: the rate
 * ("125 mL/hour") and/or how long a single administration takes ("over 4 hours").
 * Units are stored as canonical tokens (ml_per_hour, hour, minute), normalized in
 * CreatePharmacyOrderUseCase — never free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->decimal('infusion_rate_value', 8, 2)->nullable()->after('duration_unit');
            $table->string('infusion_rate_unit', 40)->nullable()->after('infusion_rate_value');
            $table->decimal('infusion_duration_value', 8, 2)->nullable()->after('infusion_rate_unit');
            $table->string('infusion_duration_unit', 40)->nullable()->after('infusion_duration_value');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'infusion_rate_value',
                'infusion_rate_unit',
                'infusion_duration_value',
                'infusion_duration_unit',
            ]);
        });
    }
};
