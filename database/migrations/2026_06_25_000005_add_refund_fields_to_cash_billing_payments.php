<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_billing_payments', function (Blueprint $table) {
            $table->decimal('refunded_amount', 15, 2)->default(0)->after('amount_paid');
            $table->timestamp('refunded_at')->nullable()->after('confirmed_by_user_id');
            $table->uuid('refunded_by_user_id')->nullable()->after('refunded_at');
            $table->text('refund_reason')->nullable()->after('refunded_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_billing_payments', function (Blueprint $table) {
            $table->dropColumn('refunded_amount');
            $table->dropColumn('refunded_at');
            $table->dropColumn('refunded_by_user_id');
            $table->dropColumn('refund_reason');
        });
    }
};
