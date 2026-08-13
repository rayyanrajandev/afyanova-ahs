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
        Schema::create('inventory_warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->string('warehouse_code', 40);
            $table->string('warehouse_name', 180);
            $table->string('warehouse_type', 60)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('contact_person', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('status', 30)->default('active');
            $table->string('status_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'warehouse_name']);
            $table->index(['facility_id', 'warehouse_name']);
            $table->index(['status', 'warehouse_name']);
            $table->index(['warehouse_type', 'status']);
            $table->unique(['tenant_id', 'facility_id', 'warehouse_code']);

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
        Schema::dropIfExists('inventory_warehouses');
    }
};

