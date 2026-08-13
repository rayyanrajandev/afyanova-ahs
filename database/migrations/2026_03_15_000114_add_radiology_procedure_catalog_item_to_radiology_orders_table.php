<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            $table->uuid('radiology_procedure_catalog_item_id')->nullable()->after('ordered_at');
            $table->string('procedure_code', 100)->nullable()->after('radiology_procedure_catalog_item_id');
            $table->index('radiology_procedure_catalog_item_id');
            $table->index('procedure_code');
            $table->foreign('radiology_procedure_catalog_item_id')
                ->references('id')
                ->on('platform_clinical_catalog_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            $table->dropForeign(['radiology_procedure_catalog_item_id']);
            $table->dropIndex(['radiology_procedure_catalog_item_id']);
            $table->dropIndex(['procedure_code']);
            $table->dropColumn(['radiology_procedure_catalog_item_id', 'procedure_code']);
        });
    }
};
