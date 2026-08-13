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
        Schema::table('appointments', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable()->after('appointment_number');
            $table->uuid('facility_id')->nullable()->after('tenant_id');

            $table->index(['tenant_id', 'scheduled_at']);
            $table->index(['facility_id', 'scheduled_at']);

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
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['facility_id']);
            $table->dropIndex(['tenant_id', 'scheduled_at']);
            $table->dropIndex(['facility_id', 'scheduled_at']);
            $table->dropColumn(['tenant_id', 'facility_id']);
        });
    }
};
