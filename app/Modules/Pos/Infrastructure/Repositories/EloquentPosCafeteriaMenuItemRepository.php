<?php

namespace App\Modules\Pos\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;
use App\Modules\Pos\Domain\ValueObjects\PosCatalogItemStatus;
use App\Modules\Pos\Infrastructure\Models\PosCafeteriaMenuItemModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentPosCafeteriaMenuItemRepository implements PosCafeteriaMenuItemRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $item = new PosCafeteriaMenuItemModel();
        $item->fill($attributes);
        $item->save();

        return $item->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = PosCafeteriaMenuItemModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query->find($id)?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = PosCafeteriaMenuItemModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $item = $query->find($id);
        if ($item === null) {
            return null;
        }

        $item->fill($attributes);
        $item->save();

        return $item->toArray();
    }

    public function findByIds(array $ids, bool $activeOnly = false): array
    {
        $normalizedIds = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $ids,
        )));

        if ($normalizedIds === []) {
            return [];
        }

        $query = PosCafeteriaMenuItemModel::query()
            ->whereIn('id', $normalizedIds)
            ->orderBy('sort_order')
            ->orderBy('item_name');
        $this->applyPlatformScopeIfEnabled($query);

        if ($activeOnly) {
            $query->where('status', PosCatalogItemStatus::ACTIVE->value);
        }

        return $query
            ->get()
            ->map(static fn (PosCafeteriaMenuItemModel $item): array => $item->toArray())
            ->all();
    }

    public function existsByItemCodeInScope(
        string $itemCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = PosCafeteriaMenuItemModel::query()
            ->whereRaw('LOWER(item_code) = ?', [strtolower(trim($itemCode))]);

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
        ?string $category,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['item_code', 'item_name', 'category', 'unit_price', 'status', 'sort_order', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'sort_order';

        $queryBuilder = PosCafeteriaMenuItemModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('item_code', 'like', $like)
                        ->orWhere('item_name', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($category, fn (Builder $builder, string $value) => $builder->whereRaw('LOWER(category) = ?', [strtolower($value)]))
            ->orderBy($sortBy, $sortDirection);

        if ($sortBy !== 'item_name') {
            $queryBuilder->orderBy('item_name');
        }

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return [
            'data' => array_map(
                static fn (PosCafeteriaMenuItemModel $item): array => $item->toArray(),
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
}
