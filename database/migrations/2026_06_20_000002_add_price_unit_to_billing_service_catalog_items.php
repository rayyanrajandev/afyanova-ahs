<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_service_catalog_items', 'price_unit')) {
                $table->string('price_unit', 50)->nullable()->after('unit');
            }

            if (! Schema::hasColumn('billing_service_catalog_items', 'units_per_pack')) {
                $table->unsignedInteger('units_per_pack')->nullable()->after('price_unit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('billing_service_catalog_items', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_service_catalog_items', 'price_unit')) {
                $table->dropColumn('price_unit');
            }

            if (Schema::hasColumn('billing_service_catalog_items', 'units_per_pack')) {
                $table->dropColumn('units_per_pack');
            }
        });
    }
};