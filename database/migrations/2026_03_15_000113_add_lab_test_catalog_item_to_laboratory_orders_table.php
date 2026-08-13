<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->uuid('lab_test_catalog_item_id')->nullable()->after('ordered_at');
            $table->index('lab_test_catalog_item_id');
            $table->foreign('lab_test_catalog_item_id')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            $table->dropForeign(['lab_test_catalog_item_id']);
            $table->dropIndex(['lab_test_catalog_item_id']);
            $table->dropColumn('lab_test_catalog_item_id');
        });
    }
};
