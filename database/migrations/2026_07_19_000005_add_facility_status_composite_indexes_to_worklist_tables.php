<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hot worklist tables that are filtered by (facility_id, status) on
     * every queue read (lab/pharmacy/radiology worklists, triage board,
     * appointments, admissions, ward tasks). None of these previously had
     * a composite index covering that exact pair — each only paired
     * facility_id with a timestamp column.
     */
    private const TABLES = [
        'laboratory_orders',
        'pharmacy_orders',
        'radiology_orders',
        'emergency_triage_cases',
        'appointments',
        'admissions',
        'inpatient_ward_tasks',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->index(['facility_id', 'status'], "{$tableName}_facility_id_status_index");
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropIndex("{$tableName}_facility_id_status_index");
            });
        }
    }
};
