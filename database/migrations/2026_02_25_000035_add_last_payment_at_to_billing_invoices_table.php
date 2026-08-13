<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->timestamp('last_payment_at')->nullable()->after('paid_amount');
            $table->index('last_payment_at');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropIndex(['last_payment_at']);
            $table->dropColumn('last_payment_at');
        });
    }
};

