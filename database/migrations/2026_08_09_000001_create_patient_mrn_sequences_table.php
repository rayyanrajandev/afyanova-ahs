<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Patient MRN sequence table.
     *
     * MRNs are sequential, zero-padded, 8-digit numbers scoped per tenant.
     * This table holds one row per scope (tenant_id, or a fixed "global"
     * scope when a tenant is not resolved in single-tenant/dev setups).
     * Values are consumed atomically inside a transaction with a row-level
     * lock (SELECT ... FOR UPDATE on Postgres) so that concurrent patient
     * registrations can never observe the same number.
     *
     * The scope key mirrors patient tenancy: `null` (dev/single-tenant) uses
     * the well-known legacy sentinel so it behaves like one shared sequence.
     */
    public function up(): void
    {
        Schema::create('patient_mrn_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 64)->unique();
            $table->unsignedBigInteger('next_value')->default(1);
            $table->timestamps();
        });

        // Backfill the sequence for each scope (tenant, or the "global"
        // sentinel for patients created without a resolved tenant) so the
        // next counter continues after every existing 8-digit MRN, without
        // rewriting or touching existing patient records. Numeric filtering
        // is done in PHP so the migration stays driver-agnostic
        // (SQLite / Postgres / MySQL). Existing non-numeric MRNs
        // (e.g. PT20260809…) are deliberately preserved as-is.
        $rows = DB::table('patients')->select('tenant_id', 'patient_number')->get();

        $maxPerScope = [];
        foreach ($rows as $row) {
            $patientNumber = $row->patient_number;
            if (! is_string($patientNumber) || ! preg_match('/^[0-9]{8}$/', $patientNumber)) {
                continue;
            }

            $tenantId = $row->tenant_id;
            $scope = $tenantId !== null && $tenantId !== ''
                ? (string) $tenantId
                : 'global';

            $maxPerScope[$scope] = max($maxPerScope[$scope] ?? 1, ((int) $patientNumber) + 1);
        }

        foreach ($maxPerScope as $scope => $nextValue) {
            DB::table('patient_mrn_sequences')->insert([
                'id' => (string) Str::uuid(),
                'scope' => $scope,
                'next_value' => $nextValue,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_mrn_sequences');
    }
};
