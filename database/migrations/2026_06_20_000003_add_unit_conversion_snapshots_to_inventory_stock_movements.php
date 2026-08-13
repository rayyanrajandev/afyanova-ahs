<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->decimal('requested_quantity', 14, 6)->nullable()->after('quantity_delta');
            $table->string('requested_unit', 50)->nullable()->after('requested_quantity');
            $table->uuid('requested_unit_id')->nullable()->after('requested_unit');
            $table->string('base_unit', 50)->nullable()->after('requested_unit_id');
            $table->decimal('base_quantity', 14, 6)->nullable()->after('base_unit');
            $table->decimal('conversion_factor', 14, 6)->nullable()->after('base_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropColumn([
                'requested_quantity',
                'requested_unit',
                'requested_unit_id',
                'base_unit',
                'base_quantity',
                'conversion_factor',
            ]);
        });
    }
};