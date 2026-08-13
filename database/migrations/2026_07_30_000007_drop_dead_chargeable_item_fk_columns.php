<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * billing-financial-state-remediation-plan.md, Phase 0. These 6 columns were added
 * 2026-07-24 (migrations 000005-000009, 000011) to link clinical orders/appointments
 * directly to a chargeable_items row, but nothing ever populated or read them --
 * pricing resolution instead relies on chargeable_items sharing a UUID with the
 * source clinical_catalog_item (ChargeableItemCatalogSync), via each order's
 * existing catalog-item FK. Dropping unused, misleadingly-present schema.
 *
 * The foreign key was created with a custom constraint name (e.g.
 * "laboratory_orders_chargeable_item_fk"), so dropping it needs that exact name on
 * Postgres/MySQL -- but SQLite's grammar refuses dropForeign() by name at all
 * ("this database driver does not support dropping foreign keys by name") and needs
 * the column-array form instead for its table-rebuild path.
 */
return new class extends Migration
{
    /** @var array<int, array{table: string, column: string, fk: string, idx: string}> */
    private array $columns = [
        ['table' => 'laboratory_orders', 'column' => 'chargeable_item_id', 'fk' => 'laboratory_orders_chargeable_item_fk', 'idx' => 'laboratory_orders_chargeable_item_id_idx'],
        ['table' => 'radiology_orders', 'column' => 'chargeable_item_id', 'fk' => 'radiology_orders_chargeable_item_fk', 'idx' => 'radiology_orders_chargeable_item_id_idx'],
        ['table' => 'pharmacy_orders', 'column' => 'chargeable_item_id', 'fk' => 'pharmacy_orders_chargeable_item_fk', 'idx' => 'pharmacy_orders_chargeable_item_id_idx'],
        ['table' => 'clinical_procedure_orders', 'column' => 'chargeable_item_id', 'fk' => 'clinical_procedure_orders_chargeable_item_fk', 'idx' => 'clinical_procedure_orders_chargeable_item_id_idx'],
        ['table' => 'theatre_procedures', 'column' => 'chargeable_item_id', 'fk' => 'theatre_procedures_chargeable_item_fk', 'idx' => 'theatre_procedures_chargeable_item_id_idx'],
        ['table' => 'appointments', 'column' => 'consultation_chargeable_item_id', 'fk' => 'appointments_consultation_chargeable_item_fk', 'idx' => 'appointments_consultation_chargeable_item_id_idx'],
    ];

    public function up(): void
    {
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';

        foreach ($this->columns as $spec) {
            Schema::table($spec['table'], function (Blueprint $table) use ($spec, $isSqlite): void {
                if (! Schema::hasColumn($spec['table'], $spec['column'])) {
                    return;
                }

                if ($isSqlite) {
                    $table->dropForeign([$spec['column']]);
                } else {
                    $table->dropForeign($spec['fk']);
                }

                $table->dropIndex($spec['idx']);
                $table->dropColumn($spec['column']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->columns as $spec) {
            Schema::table($spec['table'], function (Blueprint $table) use ($spec): void {
                if (! Schema::hasColumn($spec['table'], $spec['column'])) {
                    $table->uuid($spec['column'])->nullable();
                    $table->index($spec['column'], $spec['idx']);
                    $table->foreign($spec['column'], $spec['fk'])
                        ->references('id')
                        ->on('chargeable_items')
                        ->nullOnDelete();
                }
            });
        }
    }
};
