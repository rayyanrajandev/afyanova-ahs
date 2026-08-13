<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('patients') || Schema::hasColumn('patients', 'search_text')) {
            return;
        }

        // Trigram (pg_trgm) generated columns/indexes are Postgres-only — there is no
        // SQLite equivalent, so this is a no-op there (e.g. the SQLite-backed test suite).
        // EloquentPatientRepository::applySearchPredicate() querying `search_text` will
        // not work under SQLite as a result; that is a pre-existing, separate portability
        // gap, not something this guard attempts to fix.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // One generated column stands in for the 9 separate leading-wildcard
        // LOWER(x) LIKE '%term%' conditions EloquentPatientRepository::
        // applySearchPredicate() runs — none of those can use a plain B-tree
        // index (including first_name/national_id, added in the previous
        // migration), so every keystroke was a full table scan. Trigram
        // (pg_trgm) indexes are the standard Postgres answer for fast
        // substring search; consolidating into one column keeps write
        // overhead to a single index instead of nine. '|' separates each
        // original field/expression so a search term can only match within
        // one of them, never by spanning across two adjacent fields (e.g. the
        // end of patient_number into the start of first_name).
        DB::statement(<<<'SQL'
            ALTER TABLE patients ADD COLUMN search_text text GENERATED ALWAYS AS (
                lower(
                    coalesce(patient_number, '') || '|' ||
                    coalesce(first_name, '') || '|' ||
                    coalesce(last_name, '') || '|' ||
                    coalesce(middle_name, '') || '|' ||
                    (first_name || ' ' || last_name) || '|' ||
                    (first_name || ' ' || coalesce(middle_name, '') || ' ' || last_name) || '|' ||
                    coalesce(phone, '') || '|' ||
                    coalesce(email, '') || '|' ||
                    coalesce(national_id, '')
                )
            ) STORED
            SQL);

        DB::statement('CREATE INDEX patients_search_text_trgm_idx ON patients USING gin (search_text gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('patients') || ! Schema::hasColumn('patients', 'search_text')) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS patients_search_text_trgm_idx');
        DB::statement('ALTER TABLE patients DROP COLUMN search_text');
    }
};
