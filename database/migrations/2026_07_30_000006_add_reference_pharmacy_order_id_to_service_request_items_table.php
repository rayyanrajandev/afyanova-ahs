<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reference," not "source": this is the justification a non-clinician
 * cites when submitting a Direct Service medication request (a prior
 * dispensed order for the same, refillable_without_prescription-flagged
 * item), not the authority the new dispensing derives from — the
 * PharmacyOrder this produces still goes through the pharmacy's normal
 * review before anything is dispensed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_request_items', function (Blueprint $table): void {
            $table->foreignUuid('reference_pharmacy_order_id')
                ->nullable()
                ->after('catalog_item_id')
                ->constrained('pharmacy_orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_request_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reference_pharmacy_order_id');
        });
    }
};
