<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->string('reconciliation_decision', 100)
                ->nullable()
                ->after('reconciliation_status');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_decision');
        });
    }
};
