<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            if (! Schema::hasColumn('theatre_procedures', 'theatre_procedure_catalog_item_id')) {
                $table->uuid('theatre_procedure_catalog_item_id')
                    ->nullable()
                    ->after('appointment_id');
                $table->index('theatre_procedure_catalog_item_id', 'theatre_proc_catalog_item_id_idx');
                $table->foreign('theatre_procedure_catalog_item_id', 'theatre_proc_catalog_item_fk')
                    ->references('id')
                    ->on('platform_clinical_catalog_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('theatre_procedures', function (Blueprint $table): void {
            if (Schema::hasColumn('theatre_procedures', 'theatre_procedure_catalog_item_id')) {
                $table->dropForeign('theatre_proc_catalog_item_fk');
                $table->dropIndex('theatre_proc_catalog_item_id_idx');
                $table->dropColumn('theatre_procedure_catalog_item_id');
            }
        });
    }
};
