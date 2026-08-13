<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table): void {
            $table->uuid('inventory_item_id')->nullable()->after('item_code');
            $table->uuid('inventory_item_unit_id')->nullable()->after('inventory_item_id');
            $table->json('unit_snapshot')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table): void {
            $table->dropColumn(['inventory_item_id', 'inventory_item_unit_id', 'unit_snapshot']);
        });
    }
};