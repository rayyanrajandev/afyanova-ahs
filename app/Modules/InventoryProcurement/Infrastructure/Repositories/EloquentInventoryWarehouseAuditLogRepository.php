<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseAuditLogRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseAuditLogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryWarehouseAuditLogRepository implements InventoryWarehouseAuditLogRepositoryInterface
{
    public function write(
        string $inventoryWarehouseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void {
        InventoryWarehouseAuditLogModel::query()->create([
            'inventory_warehouse_id' => $inventoryWarehouseId,
            'action' => $action,
            'actor_id' => $actorId,
            'changes' => $changes,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function listByWarehouseId(
        string $inventoryWarehouseId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array {
        $paginator = InventoryWarehouseAuditLogModel::query()
            ->where('inventory_warehouse_id', $inventoryWarehouseId)
            ->when($query, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(action) LIKE ?', ['%'.strtolower($value).'%']))
            ->when($action, fn (Builder $builder, string $value) => $builder->where('action', $value))
            ->when($actorType === 'system', fn (Builder $builder) => $builder->whereNull('actor_id'))
            ->when($actorType === 'user', fn (Builder $builder) => $builder->whereNotNull('actor_id'))
            ->when($actorId !== null, fn (Builder $builder) => $builder->where('actor_id', $actorId))
            ->when($fromDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '>=', $value))
            ->when($toDateTime, fn (Builder $builder, string $value) => $builder->where('created_at', '<=', $value))
            ->orderByDesc('created_at')
            ->paginate(
                perPage: $perPage,
                columns: ['*'],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator);
    }

    private function toPagedResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (InventoryWarehouseAuditLogModel $log): array => $log->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
            ],
        ];
    }
}

