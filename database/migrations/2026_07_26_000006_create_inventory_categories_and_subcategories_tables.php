<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventory_MasterData_Alignment_Plan.md Phase 5. Promotes the
 * InventoryItemCategory PHP enum and the frontend's hardcoded
 * ITEM_SUBCATEGORY_OPTIONS map to real, configurable master data. The next
 * migration seeds these losslessly from both sources; InventoryExtendedController
 * then reads categoryOptions from this table instead of the enum, and a new
 * subcategoryOptions map is added to the same response so the frontend can stop
 * hand-maintaining a second copy. The enum itself is not removed -- its
 * behavioral methods (formTemplate(), requiresColdChain(), etc.) still back the
 * dynamic-rendering logic; this table is the catalog of *which* categories
 * exist and their behavior flags as data, not a replacement for the enum's
 * Domain-layer role.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('label', 120);
            $table->string('form_template', 40);
            $table->string('description', 255)->nullable();
            $table->boolean('requires_expiry_tracking')->default(false);
            $table->boolean('requires_cold_chain')->default(false);
            $table->boolean('controlled_substance_eligible')->default(false);
            $table->boolean('supports_medicine_details')->default(false);
            $table->boolean('supports_storage_fields')->default(false);
            $table->boolean('supports_clinical_classification')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('inventory_subcategories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('category_id');
            $table->string('code', 80);
            $table->string('label', 120);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'code'], 'inventory_subcategories_category_code_unique');
            $table->index('category_id', 'inventory_subcategories_category_idx');

            $table->foreign('category_id', 'inventory_subcategories_category_fk')
                ->references('id')
                ->on('inventory_categories')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_subcategories');
        Schema::dropIfExists('inventory_categories');
    }
};
