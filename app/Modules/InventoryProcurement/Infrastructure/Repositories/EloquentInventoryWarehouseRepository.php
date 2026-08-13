<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryWarehouseRepository implements InventoryWarehouseRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $warehouse = new InventoryWarehouseModel();
        $warehouse->fill($attributes);
        $warehouse->save();

        return $warehouse->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryWarehouseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $warehouse = $query->find($id);

        return $warehouse?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryWarehouseModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $warehouse = $query->find($id);
        if (! $warehouse) {
            return null;
        }

        $warehouse->fill($attributes);
        $warehouse->save();

        return $warehouse->toArray();
    }

    public function existsByWarehouseCodeInScope(
        string $warehouseCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = InventoryWarehouseModel::query()
            ->whereRaw('LOWER(warehouse_code) = ?', [strtolower(trim($warehouseCode))]);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($facilityId === null) {
            $query->whereNull('facility_id');
        } else {
            $query->where('facility_id', $facilityId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $warehouseType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['warehouse_code', 'warehouse_name', 'warehouse_type', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'warehouse_name';

        $queryBuilder = InventoryWarehouseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('warehouse_code', 'like', $like)
                        ->orWhere('warehouse_name', 'like', $like)
                        ->orWhere('warehouse_type', 'like', $like)
                        ->orWhere('location', 'like', $like)
                        ->orWhere('contact_person', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($warehouseType, fn (Builder $builder, string $value) => $builder->where('warehouse_type', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(?string $query, ?string $warehouseType): array
    {
        $queryBuilder = InventoryWarehouseModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('warehouse_code', 'like', $like)
                        ->orWhere('warehouse_name', 'like', $like)
                        ->orWhere('warehouse_type', 'like', $like)
                        ->orWhere('location', 'like', $like)
                        ->orWhere('contact_person', 'like', $like);
                });
            })
            ->when($warehouseType, fn (Builder $builder, string $value) => $builder->where('warehouse_type', $value));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'total' && $status !== 'other') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (InventoryWarehouseModel $warehouse): array => $warehouse->toArray(),
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

