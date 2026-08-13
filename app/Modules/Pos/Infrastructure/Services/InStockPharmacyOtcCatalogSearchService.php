<?php

namespace App\Modules\Pos\Infrastructure\Services;

use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Pos\Application\Support\PharmacyOtcCatalogSupport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class InStockPharmacyOtcCatalogSearchService
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly PharmacyOtcCatalogSupport $pharmacyOtcCatalogSupport,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $sortMap = [
            'code' => 'code',
            'name' => 'name',
            'category' => 'category',
            'status' => 'status',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'name'] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $queryBuilder = ClinicalCatalogItemModel::query()
            ->where('catalog_type', ClinicalCatalogType::FORMULARY_ITEM->value)
            ->where('status', 'active');

        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('unit', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($category, fn (Builder $builder, string $value) => $builder->where('category', $value))
            ->whereExists(function (QueryBuilder $subQuery): void {
                $hasClinicalCatalogLink = Schema::hasColumn('inventory_items', 'clinical_catalog_item_id');

                $subQuery
                    ->selectRaw('1')
                    ->from('inventory_items')
                    ->where('status', 'active')
                    ->where('current_stock', '>', 0)
                    ->where(function (QueryBuilder $matchQuery) use ($hasClinicalCatalogLink): void {
                        if ($hasClinicalCatalogLink) {
                            $matchQuery->whereColumn('inventory_items.clinical_catalog_item_id', 'platform_clinical_catalog_items.id');
                        }

                        $matchQuery
                            ->orWhereColumn('inventory_items.item_code', 'platform_clinical_catalog_items.code')
                            ->orWhereRaw('LOWER(inventory_items.item_name) = LOWER(platform_clinical_catalog_items.name)');
                    });

                $this->applyPlatformScopeIfEnabled(
                    $subQuery,
                    tenantColumn: 'inventory_items.tenant_id',
                    facilityColumn: 'inventory_items.facility_id',
                );
            })
            ->orderBy($sortBy, $sortDirection);

        $items = $queryBuilder
            ->get()
            ->filter(function (ClinicalCatalogItemModel $item): bool {
                $otcContext = $this->pharmacyOtcCatalogSupport->otcContext($item->toArray());
                if (! ($otcContext['otcEligible'] ?? false)) {
                    return false;
                }

                $inventoryItem = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
                    $item->code,
                    $item->name,
                );
                if ($inventoryItem === null) {
                    return false;
                }

                $availability = $this->inventoryBatchStockService->availability(
                    (string) $inventoryItem['id'],
                    now(),
                    $inventoryItem['default_warehouse_id'] ?? null,
                );

                return (float) ($availability['availableQuantity'] ?? 0) > 0;
            })
            ->values();

        $total = $items->count();
        $lastPage = max((int) ceil($total / max($perPage, 1)), 1);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $items
                ->slice($offset, $perPage)
                ->map(static fn (ClinicalCatalogItemModel $item): array => $item->toArray())
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => min($page, $lastPage),
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
            ],
        ];
    }

    private function applyPlatformScopeIfEnabled(
        Builder|QueryBuilder $query,
        string $tenantColumn = 'tenant_id',
        string $facilityColumn = 'facility_id',
    ): void {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            query: $query,
            tenantColumn: $tenantColumn,
            facilityColumn: $facilityColumn,
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
