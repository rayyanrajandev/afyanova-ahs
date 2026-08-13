<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryBatchRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryBatchRepository implements InventoryBatchRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $batch = new InventoryBatchModel();
        $batch->fill($attributes);
        $batch->save();

        return $batch->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryBatchModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryBatchModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $batch = $query->find($id);
        if (! $batch) {
            return null;
        }

        $batch->fill($attributes);
        $batch->save();

        return $batch->toArray();
    }

    public function listByItemId(string $itemId, int $page, int $perPage, ?string $status = null): array
    {
        $query = InventoryBatchModel::query()
            ->where('item_id', $itemId);
        $this->applyPlatformScopeIfEnabled($query);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $query->orderBy('expiry_date', 'asc');

        $paginator = $query->paginate(perPage: $perPage, page: $page);

        return $this->toSearchResult($paginator);
    }

    public function findByItemAndBatchNumber(string $itemId, string $batchNumber, ?string $warehouseId = null): ?array
    {
        $query = InventoryBatchModel::query()
            ->where('item_id', $itemId)
            ->where('batch_number', $batchNumber);
        $this->applyPlatformScopeIfEnabled($query);

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->first()?->toArray();
    }

    public function expiringBatches(int $withinDays, int $page, int $perPage): array
    {
        $query = InventoryBatchModel::query()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '>', now())
            ->where('expiry_date', '<=', now()->addDays($withinDays));
        $this->applyPlatformScopeIfEnabled($query);

        $query->orderBy('expiry_date', 'asc');

        return $this->toSearchResult($query->paginate(perPage: $perPage, page: $page));
    }

    public function expiredBatches(int $page, int $perPage): array
    {
        $query = InventoryBatchModel::query()
            ->where('status', 'available')
            ->where('quantity', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', now());
        $this->applyPlatformScopeIfEnabled($query);

        $query->orderBy('expiry_date', 'asc');

        return $this->toSearchResult($query->paginate(perPage: $perPage, page: $page));
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
                static fn ($model) => $model->toArray(),
                $paginator->items(),
            ),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
