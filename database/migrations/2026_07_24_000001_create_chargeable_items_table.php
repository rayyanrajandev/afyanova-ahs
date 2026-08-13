<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chargeable_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('facility_tier', 40)->nullable();
            $table->string('catalog_type', 40);
            $table->string('charge_model', 20)->default('flat');
            $table->string('code', 100);
            $table->string('name', 255);
            $table->uuid('department_id')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('default_unit', 50)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'catalog_type']);
            $table->index(['facility_id', 'catalog_type']);
            $table->index(['status', 'updated_at']);
            $table->index(['department_id', 'status']);

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
        Schema::dropIfExists('chargeable_items');
    }
};
