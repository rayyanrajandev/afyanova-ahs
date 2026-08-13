<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('radiology_orders', 'chargeable_item_id')) {
                $table->uuid('chargeable_item_id')->nullable()->after('radiology_procedure_catalog_item_id');
                $table->index('chargeable_item_id', 'radiology_orders_chargeable_item_id_idx');
                $table->foreign('chargeable_item_id', 'radiology_orders_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('radiology_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('radiology_orders', 'chargeable_item_id')) {
                $table->dropForeign('radiology_orders_chargeable_item_fk');
                $table->dropIndex('radiology_orders_chargeable_item_id_idx');
                $table->dropColumn('chargeable_item_id');
            }
        });
    }
};
