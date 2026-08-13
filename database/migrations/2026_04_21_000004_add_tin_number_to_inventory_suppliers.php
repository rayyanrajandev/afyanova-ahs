<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tin_number (Tax Identification Number) to inventory_suppliers.
 *
 * TIN is required in Tanzania for VAT-registered suppliers and is used
 * when generating purchase orders and when applying for VAT reclaim.
 * Stored as a plain string (max 30 chars) to accommodate both TZ TINs
 * (9-digit numeric) and any future international supplier TINs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table): void {
            $table->string('tin_number', 30)->nullable()->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table): void {
            $table->dropColumn('tin_number');
        });
    }
};
