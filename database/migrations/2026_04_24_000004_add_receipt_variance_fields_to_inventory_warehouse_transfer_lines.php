<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table) {
            $table->decimal('reported_received_quantity', 14, 3)->nullable()->after('received_quantity');
            $table->string('receipt_variance_type', 40)->nullable()->after('reported_received_quantity');
            $table->decimal('receipt_variance_quantity', 14, 3)->nullable()->after('receipt_variance_type');
            $table->string('receipt_variance_reason', 500)->nullable()->after('receipt_variance_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table) {
            $table->dropColumn([
                'reported_received_quantity',
                'receipt_variance_type',
                'receipt_variance_quantity',
                'receipt_variance_reason',
            ]);
        });
    }
};
