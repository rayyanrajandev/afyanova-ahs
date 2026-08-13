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
        Schema::create('facility_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('resource_type', 40);
            $table->string('code', 40);
            $table->string('name', 180);
            $table->uuid('department_id')->nullable();
            $table->string('service_point_type', 80)->nullable();
            $table->string('ward_name', 120)->nullable();
            $table->string('bed_number', 40)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'resource_type', 'name']);
            $table->index(['facility_id', 'resource_type', 'name']);
            $table->index(['resource_type', 'status']);
            $table->index(['resource_type', 'department_id']);
            $table->index(['resource_type', 'ward_name']);
            $table->unique(['tenant_id', 'facility_id', 'resource_type', 'code']);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->nullOnDelete();

            $table->foreign('facility_id')
                ->references('id')
                ->on('facilities')
                ->nullOnDelete();

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facility_resources');
    }
};

