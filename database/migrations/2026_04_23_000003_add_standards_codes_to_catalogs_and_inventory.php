<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('platform_clinical_catalog_items', 'codes')) {
                $table->json('codes')->nullable()->after('metadata');
            }
        });

        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_service_catalog_items', 'codes')) {
                $table->json('codes')->nullable()->after('metadata');
            }
        });

        Schema::table('inventory_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('inventory_items', 'codes')) {
                $table->json('codes')->nullable()->after('barcode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table): void {
            if (Schema::hasColumn('inventory_items', 'codes')) {
                $table->dropColumn('codes');
            }
        });

        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_service_catalog_items', 'codes')) {
                $table->dropColumn('codes');
            }
        });

        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('platform_clinical_catalog_items', 'codes')) {
                $table->dropColumn('codes');
            }
        });
    }
};
