<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admission V2 + real bed assignment: `ward`/`bed` were plain free-text
 * strings with occupancy computed by string-matching at write time
 * (AdmissionPlacementLookupService) — this adds a genuine relational link
 * to the bed registry (facility_resources, resource_type = 'ward_bed').
 * Nullable and not backfilled: historical free-text rows have no
 * confident 1:1 match to a specific resource row. `ward`/`bed` stay as a
 * derived display cache once a resource is linked — not removed, since
 * they're still read elsewhere (audit log changes, cross-tenant admin
 * search) and legacy string-only admission flows keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->uuid('bed_resource_id')->nullable()->after('bed');
            $table->index('bed_resource_id');

            $table->foreign('bed_resource_id')
                ->references('id')
                ->on('facility_resources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table): void {
            $table->dropForeign(['bed_resource_id']);
            $table->dropIndex(['bed_resource_id']);
            $table->dropColumn('bed_resource_id');
        });
    }
};
