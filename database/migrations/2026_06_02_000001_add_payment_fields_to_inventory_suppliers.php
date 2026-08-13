<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table): void {
            $table->string('bank_account_number', 80)->nullable()->after('tin_number');
            $table->string('lipa_number', 80)->nullable()->after('bank_account_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_suppliers', function (Blueprint $table): void {
            $table->dropColumn(['bank_account_number', 'lipa_number']);
        });
    }
};
