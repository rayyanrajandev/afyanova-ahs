<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $movements = DB::table('inventory_stock_movements')
            ->where('movement_type', 'issue')
            ->whereNotNull('destination_department_id')
            ->orderBy('occurred_at')
            ->get();

        $balances = [];

        foreach ($movements as $movement) {
            $departmentId = $movement->destination_department_id;
            $itemId = $movement->item_id;
            $batchId = $movement->batch_id;
            $key = "{$departmentId}|{$itemId}|{$batchId}";

            if (! isset($balances[$key])) {
                $balances[$key] = [
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $movement->tenant_id,
                    'facility_id' => $movement->facility_id,
                    'department_id' => $departmentId,
                    'item_id' => $itemId,
                    'batch_id' => $batchId,
                    'quantity_on_hand' => 0,
                    'quantity_consumed' => 0,
                    'quantity_returned' => 0,
                    'quantity_wasted' => 0,
                    'unit' => null,
                    'last_issued_at' => $movement->occurred_at,
                    'last_consumed_at' => null,
                    'created_at' => $movement->created_at ?? now(),
                    'updated_at' => $movement->created_at ?? now(),
                ];
            }

            $qty = (float) $movement->quantity;
            $balances[$key]['quantity_on_hand'] += $qty;
            $balances[$key]['last_issued_at'] = $movement->occurred_at;
            $balances[$key]['updated_at'] = $movement->created_at ?? now();
        }

        foreach ($balances as $balance) {
            DB::table('department_stock_balances')->updateOrInsert(
                [
                    'tenant_id' => $balance['tenant_id'],
                    'department_id' => $balance['department_id'],
                    'item_id' => $balance['item_id'],
                    'batch_id' => $balance['batch_id'],
                ],
                [
                    'id' => $balance['id'],
                    'facility_id' => $balance['facility_id'],
                    'quantity_on_hand' => $balance['quantity_on_hand'],
                    'quantity_consumed' => $balance['quantity_consumed'],
                    'quantity_returned' => $balance['quantity_returned'],
                    'quantity_wasted' => $balance['quantity_wasted'],
                    'unit' => $balance['unit'],
                    'last_issued_at' => $balance['last_issued_at'],
                    'last_consumed_at' => $balance['last_consumed_at'],
                    'created_at' => $balance['created_at'],
                    'updated_at' => $balance['updated_at'],
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('department_stock_balances')->delete();
    }
};
