<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->string('last_payment_payer_type', 50)->nullable()->after('last_payment_at');
            $table->string('last_payment_method', 50)->nullable()->after('last_payment_payer_type');
            $table->string('last_payment_reference', 120)->nullable()->after('last_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('billing_invoices', function (Blueprint $table): void {
            $table->dropColumn([
                'last_payment_payer_type',
                'last_payment_method',
                'last_payment_reference',
            ]);
        });
    }
};
