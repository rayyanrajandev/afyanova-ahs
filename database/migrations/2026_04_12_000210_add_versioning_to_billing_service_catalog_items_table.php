<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->unsignedInteger('tariff_version')->default(1)->after('service_code');
            $table->uuid('supersedes_billing_service_catalog_item_id')->nullable()->after('status_reason');

            $table->foreign('supersedes_billing_service_catalog_item_id', 'billing_service_catalog_items_supersedes_fk')
                ->references('id')
                ->on('billing_service_catalog_items')
                ->nullOnDelete();
        });

        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->dropUnique('billing_service_catalog_items_tenant_facility_code_unique');
            $table->unique(
                ['tenant_id', 'facility_id', 'service_code', 'tariff_version'],
                'billing_service_catalog_items_tenant_facility_code_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            $table->dropUnique('billing_service_catalog_items_tenant_facility_code_version_unique');
            $table->dropForeign('billing_service_catalog_items_supersedes_fk');
            $table->dropColumn(['tariff_version', 'supersedes_billing_service_catalog_item_id']);

            $table->unique(
                ['tenant_id', 'facility_id', 'service_code'],
                'billing_service_catalog_items_tenant_facility_code_unique'
            );
        });
    }
};
