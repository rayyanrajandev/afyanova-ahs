<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cafeteria_menu_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('facility_id')->nullable()->index();
            $table->string('item_code', 40)->nullable();
            $table->string('item_name', 120);
            $table->string('category', 80)->nullable();
            $table->string('unit_label', 40)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('tax_rate_percent', 5, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'facility_id', 'item_code'], 'pos_cafeteria_menu_items_scope_code_unique');
            $table->index(['status', 'category'], 'pos_cafeteria_menu_items_status_category_index');
            $table->index(['facility_id', 'status', 'sort_order'], 'pos_cafeteria_menu_items_scope_status_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cafeteria_menu_items');
    }
};
