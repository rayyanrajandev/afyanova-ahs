<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a visit's department a relationship instead of a free-text string.
 *
 * `appointments.department` is a plain `string`, while a real `departments`
 * table has existed all along with code/name/service_type/status and
 * patient-facing + appointmentable flags. The options endpoint even reads from
 * that table — and then returns the department's *display name* as the stored
 * value. So routing was resolved by name lookup
 * (DepartmentRepository::findActiveByName), which means:
 *
 *  - "Eye", "eye" and "Eye Dept" are three different departments;
 *  - renaming a department silently orphans every appointment pointing at it;
 *  - a typo produces a department that exists nowhere, and the failure is
 *    silent — ClinicalCatalogRecipeStockConsumptionService::resolveDepartmentId()
 *    simply returns null and the consumed stock is attributed to nobody.
 *
 * The string column is deliberately kept and backfilled alongside rather than
 * dropped: it is read in several modules (encounter typing, pharmacy stock,
 * board filters) and a same-migration rename would turn a data-model fix into a
 * cross-module refactor. `department_id` becomes the truth; the string stays as
 * a denormalised label until those readers move over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointments', 'department_id')) {
                $table->uuid('department_id')
                    ->nullable()
                    ->after('department');

                $table->index(['department_id', 'status']);

                $table->foreign('department_id')
                    ->references('id')
                    ->on('departments')
                    ->nullOnDelete();
            }
        });

        // Backfill by exact name — the same lookup the application already used,
        // so this cannot resolve anything the running system would not have.
        // Rows whose department string matches nothing are left null, which is
        // the honest answer: they were already unroutable.
        if (Schema::hasTable('departments')) {
            foreach (DB::table('departments')->select('id', 'name')->get() as $department) {
                DB::table('appointments')
                    ->whereNull('department_id')
                    ->where('department', $department->name)
                    ->update(['department_id' => $department->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            if (Schema::hasColumn('appointments', 'department_id')) {
                $table->dropForeign(['department_id']);
                $table->dropIndex(['department_id', 'status']);
                $table->dropColumn('department_id');
            }
        });
    }
};
