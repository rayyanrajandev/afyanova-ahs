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
        Schema::create('clinical_privilege_catalogs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('specialty_id');
            $table->string('code', 60);
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('cadre_code', 80)->nullable();
            $table->string('facility_type', 80)->nullable();
            $table->string('status', 20)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'specialty_id', 'status'], 'clinical_privilege_catalogs_specialty_status_idx');
            $table->index(['tenant_id', 'facility_type', 'status'], 'clinical_privilege_catalogs_facility_status_idx');
            $table->unique(['tenant_id', 'code']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('specialty_id')
                ->references('id')
                ->on('clinical_specialties')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinical_privilege_catalogs');
    }
};
