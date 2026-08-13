<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table): void {
            if (! Schema::hasColumn('facilities', 'facility_tier')) {
                $table->string('facility_tier', 40)->nullable()->after('facility_type')->index();
            }
        });

        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('platform_clinical_catalog_items', 'facility_tier')) {
                $table->string('facility_tier', 40)->nullable()->after('facility_id')->index();
            }
        });

        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_service_catalog_items', 'facility_tier')) {
                $table->string('facility_tier', 40)->nullable()->after('facility_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_service_catalog_items', 'facility_tier')) {
                $table->dropColumn('facility_tier');
            }
        });

        Schema::table('platform_clinical_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('platform_clinical_catalog_items', 'facility_tier')) {
                $table->dropColumn('facility_tier');
            }
        });

        Schema::table('facilities', function (Blueprint $table): void {
            if (Schema::hasColumn('facilities', 'facility_tier')) {
                $table->dropColumn('facility_tier');
            }
        });
    }
};
