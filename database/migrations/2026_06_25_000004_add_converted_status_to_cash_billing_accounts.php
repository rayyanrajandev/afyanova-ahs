<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_billing_accounts', function (Blueprint $table) {
            $table->string('status', 50)->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('cash_billing_accounts', function (Blueprint $table) {
            $table->enum('status', ['active', 'settled', 'suspended'])->default('active')->change();
        });
    }
};
