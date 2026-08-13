<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 7. Generics are routinely
 * sourced from different manufacturers across purchase orders -- fixing
 * manufacturer once at the item level (inventory_items.manufacturer, which
 * stays as a default/preference) loses that. This is the receipt-time fact:
 * which manufacturer actually supplied *this* batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->string('manufacturer', 180)->nullable()->after('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->dropColumn('manufacturer');
        });
    }
};
