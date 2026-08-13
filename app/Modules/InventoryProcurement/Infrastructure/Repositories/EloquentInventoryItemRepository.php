<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Repositories;

use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class EloquentInventoryItemRepository implements InventoryItemRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly DepartmentRequisitionScopeResolver $departmentRequisitionScopeResolver,
    ) {}

    public function create(array $attributes): array
    {
        $item = new InventoryItemModel;
        $item->fill($this->filterAttributesForCurrentSchema($attributes));
        $item->save();

        return $item->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = InventoryItemModel::query()
            ->with('clinicalCatalogItem')
            ->withCount('stockMovements')
            ->withCount(['stockMovements as opening_stock_movements_count' => fn (Builder $q) => $q->where('is_opening_stock', true)]);
        $this->applyPlatformScopeIfEnabled($query);
        $item = $query->find($id);

        return $item?->toArray();
    }

    public function findBestActiveMatchByCodeOrName(?string $itemCode, ?string $itemName): ?array
    {
        $normalizedCode = $this->normalizeLookupText($itemCode);
        $normalizedName = $this->normalizeLookupText($itemName);

        if ($normalizedCode === '' && $normalizedName === '') {
            return null;
        }

        $query = InventoryItemModel::query()
            ->where('status', 'active');
        $this->applyPlatformScopeIfEnabled($query);

        $query->where(function (Builder $builder) use ($normalizedCode, $normalizedName): void {
            if ($normalizedCode !== '') {
                $like = '%'.$normalizedCode.'%';
                $builder->orWhereRaw('LOWER(item_code) LIKE ?', [$like]);
            }

            if ($normalizedName !== '') {
                $like = '%'.$normalizedName.'%';
                $builder->orWhereRaw('LOWER(item_name) LIKE ?', [$like]);
            }
        });

        $candidates = $query
            ->limit(25)
            ->get();

        $bestMatch = $candidates
            ->map(fn (InventoryItemModel $item) => [
                'item' => $item,
                'score' => $this->inventoryItemMatchScore(
                    itemCode: (string) $item->item_code,
                    itemName: (string) $item->item_name,
                    requestedCode: $normalizedCode,
                    requestedName: $normalizedName,
                ),
            ])
            ->filter(fn (array $entry): bool => $entry['score'] > 0)
            ->sort(function (array $left, array $right): int {
                if ($right['score'] !== $left['score']) {
                    return $right['score'] <=> $left['score'];
                }

                return strcmp(
                    (string) ($left['item']->item_name ?? ''),
                    (string) ($right['item']->item_name ?? ''),
                );
            })
            ->first();

        if ($bestMatch === null) {
            return null;
        }

        return $bestMatch['item']->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = InventoryItemModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $item = $query->find($id);
        if (! $item) {
            return null;
        }

        $item->fill($this->filterAttributesForCurrentSchema($attributes));
        $item->save();

        return $item->toArray();
    }

    public function existsByItemCode(string $itemCode, ?string $excludeId = null): bool
    {
        return InventoryItemModel::query()
            ->where('item_code', $itemCode)
            ->when(
                $excludeId,
                fn (Builder $builder, string $id) => $builder->where('id', '!=', $id),
            )
            ->exists();
    }

    public function search(
        ?string $query,
        ?string $category,
        ?string $subcategory,
        ?string $requestingDepartmentId,
        ?string $stockState,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        // generic_name dropped from inventory_items in Phase 3 (Inventory_MasterData_Alignment_Plan.md)
        // -- it's Pharmaceutical-only now, living on the joined clinical catalog row.
        // Sorting across that join isn't supported here; falls back to item_name, same
        // as any other unrecognized sort key.
        $sortBy = in_array($sortBy, ['item_code', 'item_name', 'category', 'current_stock', 'reorder_level', 'created_at', 'updated_at', 'ven_classification', 'manufacturer'], true)
            ? $sortBy
            : 'item_name';

        $queryBuilder = InventoryItemModel::query()
            ->with('clinicalCatalogItem')
            ->withCount('stockMovements')
            ->withCount(['stockMovements as opening_stock_movements_count' => fn (Builder $q) => $q->where('is_opening_stock', true)]);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($category, fn (Builder $builder, string $requestedCategory) => $builder->where('category', $requestedCategory))
            ->when($subcategory, fn (Builder $builder, string $requestedSubcategory) => $builder->where('subcategory', $requestedSubcategory))
            ->when($stockState === 'out_of_stock', fn (Builder $builder) => $builder->where('current_stock', '<=', 0))
            ->when($stockState === 'low_stock', fn (Builder $builder) => $builder->where('current_stock', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level'))
            ->when($stockState === 'healthy', fn (Builder $builder) => $builder->whereColumn('current_stock', '>', 'reorder_level'))
            ->orderBy($sortBy, $sortDirection);

        $this->departmentRequisitionScopeResolver->applyItemScope($queryBuilder, $requestingDepartmentId);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function findLinkedClinicalCatalogItemIds(array $catalogItemIds): array
    {
        return InventoryItemModel::query()
            ->whereIn('clinical_catalog_item_id', $catalogItemIds)
            ->whereNotNull('clinical_catalog_item_id')
            ->pluck('clinical_catalog_item_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    public function listLinkedByClinicalCatalogIds(array $catalogItemIds): array
    {
        if ($catalogItemIds === []) {
            return [];
        }

        $rows = InventoryItemModel::query()
            ->whereIn('clinical_catalog_item_id', $catalogItemIds)
            ->whereNotNull('clinical_catalog_item_id')
            ->get()
            ->map(fn (InventoryItemModel $item): array => $item->toArray())
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $cciId = (string) ($row['clinical_catalog_item_id'] ?? '');
            if ($cciId !== '' && ! isset($map[$cciId])) {
                $map[$cciId] = $row;
            }
        }

        return $map;
    }

    public function listExistingItemCodes(): array
    {
        return InventoryItemModel::query()
            ->select('item_code')
            ->get()
            ->pluck('item_code')
            ->filter()
            ->values()
            ->all();
    }

    public function stockAlertCounts(?string $query, ?string $category, ?string $requestingDepartmentId = null): array
    {
        $queryBuilder = InventoryItemModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($category, fn (Builder $builder, string $requestedCategory) => $builder->where('category', $requestedCategory));

        $this->departmentRequisitionScopeResolver->applyItemScope($queryBuilder, $requestingDepartmentId);

        $all = (clone $queryBuilder)->count();
        $outOfStock = (clone $queryBuilder)->where('current_stock', '<=', 0)->count();
        $lowStock = (clone $queryBuilder)->where('current_stock', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level')->count();
        $healthy = max($all - $outOfStock - $lowStock, 0);

        return [
            'outOfStock' => $outOfStock,
            'lowStock' => $lowStock,
            'healthy' => $healthy,
            'total' => $all,
        ];
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function applySearchFilter(Builder $query, string $searchTerm): void
    {
        $normalizedSearchTerm = $this->normalizeLookupText($searchTerm);
        if ($normalizedSearchTerm === '') {
            return;
        }

        $like = '%'.$normalizedSearchTerm.'%';

        // LEFT JOIN clinical catalog to enable search across linked catalog item names/codes
        $query->leftJoin(
            'platform_clinical_catalog_items AS cci',
            'inventory_items.clinical_catalog_item_id',
            '=',
            'cci.id',
        );

        $query->where(function (Builder $nestedQuery) use ($like): void {
            $nestedQuery
                ->whereRaw('LOWER(inventory_items.item_code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.item_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.category) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.subcategory) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.msd_code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.nhif_code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.barcode) LIKE ?', [$like])
                ->orWhereRaw('LOWER(inventory_items.manufacturer) LIKE ?', [$like])
                // Search linked clinical catalog item name/code and, since Phase 3
                // (Inventory_MasterData_Alignment_Plan.md) dropped generic_name/strength/
                // dosage_form from inventory_items, their catalog-owned replacements too.
                ->orWhereRaw('LOWER(cci.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(cci.code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(cci.generic_name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(cci.strength) LIKE ?', [$like])
                ->orWhereRaw('LOWER(cci.dosage_form) LIKE ?', [$like]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributesForCurrentSchema(array $attributes): array
    {
        if (! Schema::hasColumn('inventory_items', 'codes')) {
            unset($attributes['codes']);
        }

        if (! Schema::hasColumn('inventory_items', 'clinical_catalog_item_id')) {
            unset($attributes['clinical_catalog_item_id']);
        }

        return $attributes;
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }

    private function normalizeLookupText(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function inventoryItemMatchScore(
        string $itemCode,
        string $itemName,
        string $requestedCode,
        string $requestedName
    ): int {
        $normalizedItemCode = $this->normalizeLookupText($itemCode);
        $normalizedItemName = $this->normalizeLookupText($itemName);

        $score = 0;

        if ($requestedCode !== '') {
            if ($normalizedItemCode === $requestedCode) {
                $score = max($score, 700);
            } elseif (str_ends_with($normalizedItemCode, '-'.$requestedCode)) {
                $score = max($score, 560);
            } elseif (str_contains($normalizedItemCode, $requestedCode)) {
                $score = max($score, 460);
            }
        }

        if ($requestedName !== '') {
            if ($normalizedItemName === $requestedName) {
                $score = max($score, 640);
            } elseif (
                str_contains($normalizedItemName, $requestedName)
                || str_contains($requestedName, $normalizedItemName)
            ) {
                $score = max($score, 420);
            }
        }

        return $score;
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (InventoryItemModel $item): array => $item->toArray(),
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
