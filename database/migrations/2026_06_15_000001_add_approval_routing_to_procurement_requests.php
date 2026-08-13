<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hybrid procurement model: Routes requests to central store based on:
     * - Whether item is in department's approved catalog
     * - Quantity vs. department threshold
     * - Budget constraints
     */
    public function up(): void
    {
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            // Which department initiated this procurement request
            $table->uuid('requesting_department_id')->nullable()->after('facility_id')->index();

            // Routing determination: 'auto' (direct to supplier) or 'needs_review' (central store approval)
            $table->string('approval_route', 30)->default('needs_review')->after('requesting_department_id');

            // Whether this item is in the requesting department's approved catalog
            $table->boolean('is_catalog_item')->default(false)->after('approval_route');

            // Hash for duplicate detection: md5(department_id . item_id . status)
            // Helps prevent duplicate active requests for same item in same department
            $table->string('duplicate_check_hash', 64)->nullable()->after('is_catalog_item')->index();

            // Foreign key to departments table
            $table->foreign('requesting_department_id')
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
        Schema::table('inventory_procurement_requests', function (Blueprint $table): void {
            $table->dropForeignKeyIfExists('inventory_procurement_requests_requesting_department_id_foreign');
            $table->dropIndex('inventory_procurement_requests_requesting_department_id_index');
            $table->dropIndex('inventory_procurement_requests_duplicate_check_hash_index');
            $table->dropColumn(['requesting_department_id', 'approval_route', 'is_catalog_item', 'duplicate_check_hash']);
        });
    }
};
