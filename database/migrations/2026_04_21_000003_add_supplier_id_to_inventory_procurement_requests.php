<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a proper FK supplier_id to inventory_procurement_requests so that
 * purchase requests and orders are connected to a real inventory_suppliers
 * record rather than a typed supplier_name string.
 *
 * supplier_name is retained as a nullable fallback for ad-hoc/walk-in
 * situations where the supplier is not yet registered in the system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->uuid('supplier_id')->nullable()->after('facility_id');
        });

        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->foreign('supplier_id')
                ->references('id')
                ->on('inventory_suppliers')
                ->nullOnDelete();

            $table->index(['supplier_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['supplier_id', 'created_at']);
            $table->dropColumn('supplier_id');
        });
    }
};
