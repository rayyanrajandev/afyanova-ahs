<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('laboratory_orders', 'chargeable_item_id')) {
                $table->uuid('chargeable_item_id')->nullable()->after('lab_test_catalog_item_id');
                $table->index('chargeable_item_id', 'laboratory_orders_chargeable_item_id_idx');
                $table->foreign('chargeable_item_id', 'laboratory_orders_chargeable_item_fk')
                    ->references('id')
                    ->on('chargeable_items')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('laboratory_orders', 'chargeable_item_id')) {
                $table->dropForeign('laboratory_orders_chargeable_item_fk');
                $table->dropIndex('laboratory_orders_chargeable_item_id_idx');
                $table->dropColumn('chargeable_item_id');
            }
        });
    }
};
