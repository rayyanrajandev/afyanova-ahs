<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'medical_records',
        'laboratory_orders',
        'pharmacy_orders',
        'radiology_orders',
        'theatre_procedures',
        'clinical_procedure_orders',
        'billing_invoices',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'encounter_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $afterColumn = Schema::hasColumn($tableName, 'appointment_id')
                    ? 'appointment_id'
                    : 'patient_id';

                $table->uuid('encounter_id')->nullable()->after($afterColumn);
                $table->index('encounter_id');
                $table->foreign('encounter_id')
                    ->references('id')
                    ->on('encounters')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'encounter_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['encounter_id']);
                $table->dropIndex(['encounter_id']);
                $table->dropColumn('encounter_id');
            });
        }
    }
};
