<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_clinical_catalog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('catalog_type', 40);
            $table->string('code', 100);
            $table->string('name', 255);
            $table->uuid('department_id')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('unit', 50)->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['catalog_type', 'status']);
            $table->index(['tenant_id', 'catalog_type']);
            $table->index(['facility_id', 'catalog_type']);
            $table->index(['department_id', 'status']);
            $table->unique(
                ['tenant_id', 'facility_id', 'catalog_type', 'code'],
                'platform_clinical_catalog_items_scope_type_code_unique'
            );

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

    public function down(): void
    {
        Schema::dropIfExists('platform_clinical_catalog_items');
    }
};
