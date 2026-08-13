<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryMsdOrderRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryMsdOrderModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentInventoryMsdOrderRepository implements InventoryMsdOrderRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $order = new InventoryMsdOrderModel();
        $order->fill($attributes);
        $order->save();

        return $order->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryMsdOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $order = $query->find($id);

        return $order?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryMsdOrderModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $order = $query->find($id);
        if (! $order) {
            return null;
        }

        $order->fill($attributes);
        $order->save();

        return $order->toArray();
    }

    public function search(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage
    ): array {
        $builder = InventoryMsdOrderModel::query();
        $this->applyPlatformScopeIfEnabled($builder);

        if ($query !== null && trim($query) !== '') {
            $term = '%' . trim($query) . '%';
            $builder->where(function (Builder $q) use ($term) {
                $q->where('msd_order_number', 'LIKE', $term)
                    ->orWhere('facility_msd_code', 'LIKE', $term)
                    ->orWhere('submission_reference', 'LIKE', $term)
                    ->orWhere('delivery_note_number', 'LIKE', $term)
                    ->orWhere('notes', 'LIKE', $term);
            });
        }

        if ($status !== null && trim($status) !== '') {
            $builder->where('status', $status);
        }

        $builder->orderByDesc('created_at');

        return $this->toSearchResult($builder->paginate(perPage: $perPage, page: $page));
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
        // 'inventory_procurement_platform_scoping' isn't a registered flag
        // key (the real ones are 'platform.multi_facility_scoping'/
        // 'platform.multi_tenant_isolation') — this silently returned false
        // regardless of the real flags' state.
        try {
            return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
                || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
        } catch (\Throwable) {
            return false;
        }
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => collect($paginator->items())->map(fn ($m) => $m->toArray())->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
