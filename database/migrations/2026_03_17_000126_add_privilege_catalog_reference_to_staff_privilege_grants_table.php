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
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->uuid('privilege_catalog_id')
                ->nullable()
                ->after('specialty_id');

            $table->index(['privilege_catalog_id']);

            $table->foreign('privilege_catalog_id')
                ->references('id')
                ->on('clinical_privilege_catalogs')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('staff_privilege_grants', function (Blueprint $table): void {
            $table->dropForeign(['privilege_catalog_id']);
            $table->dropIndex(['privilege_catalog_id']);
            $table->dropColumn('privilege_catalog_id');
        });
    }
};
