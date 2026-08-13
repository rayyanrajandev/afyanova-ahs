<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('pharmacy_orders', 'approved_medicine_catalog_item_id')) {
                $table->uuid('approved_medicine_catalog_item_id')->nullable()->after('ordered_at');
                $table->index('approved_medicine_catalog_item_id');
                $table->foreign('approved_medicine_catalog_item_id')
                    ->references('id')
                    ->on('platform_clinical_catalog_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('pharmacy_orders', 'approved_medicine_catalog_item_id')) {
                $table->dropForeign(['approved_medicine_catalog_item_id']);
                $table->dropIndex(['approved_medicine_catalog_item_id']);
                $table->dropColumn('approved_medicine_catalog_item_id');
            }
        });
    }
};