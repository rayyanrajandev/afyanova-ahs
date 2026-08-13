<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('id');
        });

        // batch_number is the vendor/lot-supplied value and is only unique
        // per (item, warehouse) — it regularly collides across items, so it
        // can't be copied straight into the now-globally-unique
        // internal_batch_number. internal_batch_number is shown directly to
        // staff (dispense dialog, stock control, reports), so keep the
        // familiar batch_number for the first occurrence and only suffix
        // the rows that actually collide, rather than replacing everything
        // with an opaque generated code.
        $used = [];
        DB::table('inventory_batches')
            ->whereNull('internal_batch_number')
            ->orderBy('batch_number')
            ->orderBy('id')
            ->select('id', 'batch_number')
            ->get()
            ->each(function ($batch) use (&$used): void {
                $base = substr((string) $batch->batch_number, 0, 100);
                $candidate = $base;
                $suffix = 2;
                while (isset($used[$candidate])) {
                    $candidate = substr($base, 0, 100 - strlen('-' . $suffix)) . '-' . $suffix;
                    $suffix++;
                }
                $used[$candidate] = true;

                DB::table('inventory_batches')
                    ->where('id', $batch->id)
                    ->update(['internal_batch_number' => $candidate]);
            });

        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable(false)->change();

            $table->unique('internal_batch_number', 'inv_batches_internal_batch_unique');

            $table->string('batch_number', 100)->nullable()->change();

            $table->dropUnique('inv_batch_item_batch_wh_unique');
        });

        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });

        Schema::table('inventory_stock_reservations', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });

        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });

        Schema::table('inventory_dispensing_claim_links', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });

        Schema::table('department_stock_movements', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });

        Schema::table('department_stock_balances', function (Blueprint $table): void {
            $table->string('internal_batch_number', 100)->nullable()->after('batch_id');
        });
    }

    public function down(): void
    {
        Schema::table('department_stock_balances', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('department_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('inventory_dispensing_claim_links', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('inventory_warehouse_transfer_lines', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('inventory_stock_reservations', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('inventory_stock_movements', function (Blueprint $table): void {
            $table->dropColumn('internal_batch_number');
        });

        Schema::table('inventory_batches', function (Blueprint $table): void {
            $table->dropUnique('inv_batches_internal_batch_unique');
            $table->string('batch_number', 100)->nullable(false)->change();
            $table->unique(['item_id', 'batch_number', 'warehouse_id'], 'inv_batch_item_batch_wh_unique');
            $table->dropColumn('internal_batch_number');
        });
    }
};
