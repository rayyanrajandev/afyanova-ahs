<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\DepartmentStockBalanceRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\DepartmentStockBalanceModel;
use Illuminate\Support\Facades\DB;

class EloquentDepartmentStockBalanceRepository implements DepartmentStockBalanceRepositoryInterface
{
    public function findOrCreateBalance(
        string $tenantId,
        string $departmentId,
        string $itemId,
        ?string $batchId,
        ?string $unit,
    ): array {
        $existing = DepartmentStockBalanceModel::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $departmentId)
            ->where('item_id', $itemId)
            ->where('batch_id', $batchId)
            ->first();

        if ($existing) {
            return $existing->toArray();
        }

        $balance = DepartmentStockBalanceModel::create([
            'tenant_id' => $tenantId,
            'department_id' => $departmentId,
            'item_id' => $itemId,
            'batch_id' => $batchId,
            'quantity_on_hand' => 0,
            'quantity_consumed' => 0,
            'quantity_returned' => 0,
            'quantity_wasted' => 0,
            'unit' => $unit,
        ]);

        return $balance->toArray();
    }

    public function incrementOnHand(
        string $balanceId,
        float $quantity,
        ?string $unit = null,
    ): array {
        $balance = DepartmentStockBalanceModel::findOrFail($balanceId);

        $balance->update([
            'quantity_on_hand' => $balance->quantity_on_hand + $quantity,
            'last_issued_at' => now(),
            'unit' => $unit ?? $balance->unit,
        ]);

        return $balance->fresh()->toArray();
    }

    public function decrementOnHand(
        string $balanceId,
        float $quantity,
    ): array {
        $balance = DepartmentStockBalanceModel::findOrFail($balanceId);

        $newOnHand = $balance->quantity_on_hand - $quantity;
        if ($newOnHand < 0) {
            $newOnHand = 0;
        }

        $balance->update([
            'quantity_on_hand' => $newOnHand,
        ]);

        return $balance->fresh()->toArray();
    }

    public function recordConsumption(
        string $balanceId,
        float $quantity,
    ): array {
        $balance = DepartmentStockBalanceModel::findOrFail($balanceId);

        $newOnHand = $balance->quantity_on_hand - $quantity;
        if ($newOnHand < 0) {
            $newOnHand = 0;
        }

        $balance->update([
            'quantity_on_hand' => $newOnHand,
            'quantity_consumed' => $balance->quantity_consumed + $quantity,
            'last_consumed_at' => now(),
        ]);

        return $balance->fresh()->toArray();
    }

    public function recordReturn(
        string $balanceId,
        float $quantity,
    ): array {
        $balance = DepartmentStockBalanceModel::findOrFail($balanceId);

        $balance->update([
            'quantity_on_hand' => $balance->quantity_on_hand - $quantity,
            'quantity_returned' => $balance->quantity_returned + $quantity,
        ]);

        return $balance->fresh()->toArray();
    }

    public function recordWastage(
        string $balanceId,
        float $quantity,
    ): array {
        $balance = DepartmentStockBalanceModel::findOrFail($balanceId);

        $newOnHand = $balance->quantity_on_hand - $quantity;
        if ($newOnHand < 0) {
            $newOnHand = 0;
        }

        $balance->update([
            'quantity_on_hand' => $newOnHand,
            'quantity_wasted' => $balance->quantity_wasted + $quantity,
        ]);

        return $balance->fresh()->toArray();
    }

    public function findByDepartmentAndItem(
        string $departmentId,
        string $itemId,
    ): ?array {
        $balance = DepartmentStockBalanceModel::query()
            ->where('department_id', $departmentId)
            ->where('item_id', $itemId)
            ->first();

        return $balance?->toArray();
    }

    public function listByDepartment(
        string $departmentId,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $query = DepartmentStockBalanceModel::query()
            ->where('department_id', $departmentId)
            ->where('quantity_on_hand', '>', 0)
            ->with(['item']);

        if ($search) {
            $query->whereHas('item', function ($q) use ($search) {
                $q->where('item_name', 'ilike', "%{$search}%")
                    ->orWhere('item_code', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->orderBy('updated_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => $paginator->items(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }

    public function summaryByDepartment(string $departmentId): array
    {
        $balances = DepartmentStockBalanceModel::query()
            ->where('department_id', $departmentId)
            ->where('quantity_on_hand', '>', 0)
            ->get();

        return [
            'totalItems' => $balances->count(),
            'totalOnHand' => $balances->sum('quantity_on_hand'),
            'totalConsumed' => $balances->sum('quantity_consumed'),
            'totalReturned' => $balances->sum('quantity_returned'),
            'totalWasted' => $balances->sum('quantity_wasted'),
            'lowStockItems' => $balances->filter(fn ($b) => $b->quantity_on_hand > 0 && $b->quantity_on_hand <= 10)->count(),
        ];
    }
}
