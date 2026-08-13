<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_register_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('void_count')->default(0)->after('sale_count');
            $table->unsignedInteger('refund_count')->default(0)->after('void_count');
            $table->decimal('adjustment_amount', 12, 2)->default(0)->after('refund_count');
            $table->decimal('cash_adjustment_amount', 12, 2)->default(0)->after('adjustment_amount');
            $table->decimal('non_cash_adjustment_amount', 12, 2)->default(0)->after('cash_adjustment_amount');
        });
    }

    public function down(): void
    {
        Schema::table('pos_register_sessions', function (Blueprint $table): void {
            $table->dropColumn([
                'void_count',
                'refund_count',
                'adjustment_amount',
                'cash_adjustment_amount',
                'non_cash_adjustment_amount',
            ]);
        });
    }
};
