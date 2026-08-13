<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct Service (VisitCategory::DIRECT_SERVICE) lets front desk create a
 * department request for a patient who explicitly doesn't need a clinician
 * consultation, but front desk must not be able to freely pick any catalog
 * item — that reintroduces the same "non-clinician decides medication"
 * risk the nurse-led-walk-in change was meant to close. Both flags default
 * false (opt-in, set per item by a pharmacist/clinician/admin):
 * - direct_service_eligible: this test/service/procedure can be requested
 *   directly at all (e.g. a BP check, a malaria RDT).
 * - refillable_without_prescription: for pharmacy items specifically, this
 *   medication can be requested by referencing a prior dispensed order for
 *   the same item, without a new prescription (chronic maintenance
 *   medications only — short-course/controlled drugs stay unflagged).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->boolean('direct_service_eligible')->default(false)->after('controlled_substance_schedule');
            $table->boolean('refillable_without_prescription')->default(false)->after('direct_service_eligible');
            $table->index('direct_service_eligible');
            $table->index('refillable_without_prescription');
        });
    }

    public function down(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->dropColumn(['direct_service_eligible', 'refillable_without_prescription']);
        });
    }
};
