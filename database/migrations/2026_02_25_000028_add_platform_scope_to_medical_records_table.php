<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('record_number');
            $table->uuid('facility_id')->nullable()->after('tenant_id');

            $table->index(['tenant_id', 'encounter_at']);
            $table->index(['facility_id', 'encounter_at']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['facility_id']);
            $table->dropIndex(['tenant_id', 'encounter_at']);
            $table->dropIndex(['facility_id', 'encounter_at']);
            $table->dropColumn(['tenant_id', 'facility_id']);
        });
    }
};
