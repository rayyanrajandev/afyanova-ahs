<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make patient MRN uniqueness tenant-scoped.
     *
     * MRNs restart at 00000001 in every tenant (Facility A: 00000001….,
     * Facility B: 00000001….), so the global UNIQUE index on patient_number
     * is replaced with a composite UNIQUE over (tenant_id, patient_number).
     *
     * Patients without a resolved tenant (dev, single-tenant, legacy rows
     * with NULL tenant_id) are additionally guarded by a partial UNIQUE
     * index on patient_number alone. Laravel's Blueprint `->whereNull()`
     * does not emit partial-index syntax on SQLite (it silently creates a
     * global index), so the partial index is created via raw SQL — the
     * syntax is identical on PostgreSQL and SQLite. MySQL does not support
     * partial indexes; the environment targets SQLite (dev/tests) and
     * PostgreSQL (prod).
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('patients'))->keyBy('name');
            $global = $indexes->first(fn ($index) => ($index['unique'] ?? false) && ($index['columns'] ?? []) === ['patient_number']);

            if ($global) {
                $table->dropUnique($global['name']);
            }

            if (! $indexes->has('patients_tenant_id_patient_number_unique')) {
                $table->unique(['tenant_id', 'patient_number'], 'patients_tenant_id_patient_number_unique');
            }
        });

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS patients_patient_number_no_tenant_unique '
                .'ON patients (patient_number) WHERE tenant_id IS NULL'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            DB::statement('DROP INDEX IF EXISTS patients_patient_number_no_tenant_unique');
        }

        Schema::table('patients', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('patients'))->keyBy('name');

            if ($indexes->has('patients_tenant_id_patient_number_unique')) {
                $table->dropUnique('patients_tenant_id_patient_number_unique');
            }

            $table->unique('patient_number', 'patients_patient_number_unique');
        });
    }
};
