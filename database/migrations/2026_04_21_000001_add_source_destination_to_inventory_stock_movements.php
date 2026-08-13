<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->string('source_location', 500)->nullable()->after('adjustment_direction');
            $table->string('destination_location', 500)->nullable()->after('source_location');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropColumn(['source_location', 'destination_location']);
        });
    }
};
