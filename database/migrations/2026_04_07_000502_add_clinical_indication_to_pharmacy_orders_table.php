<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->string('clinical_indication', 255)
                ->nullable()
                ->after('dosage_instruction');
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', function (Blueprint $table): void {
            $table->dropColumn('clinical_indication');
        });
    }
};
