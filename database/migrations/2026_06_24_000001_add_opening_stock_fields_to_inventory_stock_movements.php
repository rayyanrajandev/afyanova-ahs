<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->boolean('is_opening_stock')->default(false)->after('movement_type');
            $table->string('reason_code', 50)->nullable()->after('reason');
            $table->uuid('superseded_by_id')->nullable()->after('metadata');
            $table->string('approval_status', 30)->default('approved')->after('superseded_by_id');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approved_by_id')->nullable()->after('approved_at');

            $table->index(['is_opening_stock', 'occurred_at']);
            $table->index('reason_code');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropIndex(['is_opening_stock', 'occurred_at']);
            $table->dropIndex(['reason_code']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn([
                'is_opening_stock',
                'reason_code',
                'superseded_by_id',
                'approval_status',
                'approved_at',
                'approved_by_id',
            ]);
        });
    }
};
