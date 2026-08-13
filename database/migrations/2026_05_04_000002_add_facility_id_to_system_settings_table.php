<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            // Allows the same setting key to have a global default (facility_id IS NULL)
            // and a facility-specific override (facility_id = <uuid>).
            $table->uuid('facility_id')->nullable()->after('group');

            // Drop the single-column unique on key since we now allow the same key
            // per facility.
            $table->dropUnique(['key']);

            // New composite unique: one value per (facility_id, key) pair.
            // NULL facility_id counts as the global default row.
            $table->unique(['facility_id', 'key'], 'system_settings_facility_key_unique');

            // Index for fast per-facility look-ups.
            $table->index(['facility_id', 'group'], 'system_settings_facility_group_idx');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->dropIndex('system_settings_facility_group_idx');
            $table->dropUnique('system_settings_facility_key_unique');
            $table->dropColumn('facility_id');

            // Restore the original single-column unique.
            $table->unique('key');
        });
    }
};
