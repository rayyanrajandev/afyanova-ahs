<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('item_id');
            $table->string('unit_name', 50);
            $table->string('unit_code', 50)->nullable();
            $table->decimal('base_quantity', 14, 6);
            $table->boolean('is_base_unit')->default(false);
            $table->boolean('is_default_sales_unit')->default(false);
            $table->boolean('is_default_purchase_unit')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('barcode', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'unit_name'], 'inv_item_units_item_unit_unique');
            $table->unique(['item_id', 'barcode'], 'inv_item_units_item_barcode_unique');
            $table->index(['tenant_id', 'facility_id']);
            $table->index(['item_id', 'is_active']);
            $table->index(['barcode']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->nullOnDelete();
            $table->foreign('item_id')->references('id')->on('inventory_items')->cascadeOnDelete();
        });

        // PostgreSQL supports CHECK constraints via ALTER TABLE;
        // SQLite does not, so we skip. The validation is enforced
        // at the application layer for SQLite-compatible environments.
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE inventory_item_units ADD CONSTRAINT inv_item_units_base_quantity_positive CHECK (base_quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_units');
    }
};