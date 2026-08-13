<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouse_transfers', function (Blueprint $table) {
            $table->string('receipt_variance_review_status', 30)->nullable()->after('received_at');
            $table->bigInteger('receipt_variance_reviewed_by_user_id')->unsigned()->nullable()->after('received_by_user_id');
            $table->timestamp('receipt_variance_reviewed_at')->nullable()->after('received_at');
            $table->text('receipt_variance_review_notes')->nullable()->after('receiving_notes');

            $table->foreign('receipt_variance_reviewed_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->index(['receipt_variance_review_status', 'received_at'], 'inventory_warehouse_transfers_review_status_received_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouse_transfers', function (Blueprint $table) {
            $table->dropForeign(['receipt_variance_reviewed_by_user_id']);
            $table->dropIndex('inventory_warehouse_transfers_review_status_received_at_idx');
            $table->dropColumn([
                'receipt_variance_review_status',
                'receipt_variance_reviewed_by_user_id',
                'receipt_variance_reviewed_at',
                'receipt_variance_review_notes',
            ]);
        });
    }
};
