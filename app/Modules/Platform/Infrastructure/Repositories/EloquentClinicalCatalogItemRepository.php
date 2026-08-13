<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Support\CatalogGovernance\ChargeableItemCatalogSync;
use App\Support\CatalogGovernance\FacilityTierSupport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class EloquentClinicalCatalogItemRepository implements ClinicalCatalogItemRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly ChargeableItemCatalogSync $chargeableItemCatalogSync,
    ) {}

    public function create(array $attributes): array
    {
        $item = new ClinicalCatalogItemModel();
        $item->fill($this->filterAttributesForCurrentSchema($attributes));
        $item->save();

        $this->chargeableItemCatalogSync->sync($item);

        return $item->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = ClinicalCatalogItemModel::query();
        $this->applyPlatformScope($query);
        $item = $query->find($id);

        return $item?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ClinicalCatalogItemModel::query();
        $this->applyPlatformScope($query);
        $item = $query->find($id);
        if (! $item) {
            return null;
        }

        $item->fill($this->filterAttributesForCurrentSchema($attributes));
        $item->save();

        $this->chargeableItemCatalogSync->sync($item);

        return $item->toArray();
    }

    public function existsByCodeInScope(
        string $catalogType,
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = ClinicalCatalogItemModel::query()
            ->where('catalog_type', $catalogType)
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))]);

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

    public function findIdByCodeInScope(
        string $catalogType,
        string $code,
        ?string $tenantId,
        ?string $facilityId,
    ): ?string {
        $query = ClinicalCatalogItemModel::query()
            ->where('catalog_type', $catalogType)
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))]);

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

        $id = $query->value('id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function search(
        string $catalogType,
        ?string $query,
        ?string $status,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?array $ids = null,
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'category', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = ClinicalCatalogItemModel::query()
            ->where('catalog_type', $catalogType);
        $this->applyPlatformScope($queryBuilder);
        app(FacilityTierSupport::class)->applyAvailabilityFilter(
            $queryBuilder,
            'platform_clinical_catalog_items',
            app(CurrentPlatformScopeContextInterface::class)->facilityId(),
        );

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($category, fn (Builder $builder, string $value) => $builder->where('category', $value))
            ->when($dosageForm, fn (Builder $builder, string $value) => $builder->where('metadata->dosageForm', $value))
            ->when($ids !== null && $ids !== [], fn (Builder $builder) => $builder->whereIn('id', $ids))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function searchActiveForSync(array $catalogTypes): array
    {
        $queryBuilder = ClinicalCatalogItemModel::query()
            ->whereIn('catalog_type', $catalogTypes)
            ->where('status', 'active');
        $this->applyPlatformScope($queryBuilder);
        app(FacilityTierSupport::class)->applyAvailabilityFilter(
            $queryBuilder,
            'platform_clinical_catalog_items',
            app(CurrentPlatformScopeContextInterface::class)->facilityId(),
        );

        return $queryBuilder->orderBy('name')->get()->toArray();
    }

    public function statusCounts(
        string $catalogType,
        ?string $query,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
    ): array {
        $queryBuilder = ClinicalCatalogItemModel::query()
            ->where('catalog_type', $catalogType);
        $this->applyPlatformScope($queryBuilder);
        app(FacilityTierSupport::class)->applyAvailabilityFilter(
            $queryBuilder,
            'platform_clinical_catalog_items',
            app(CurrentPlatformScopeContextInterface::class)->facilityId(),
        );

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($category, fn (Builder $builder, string $value) => $builder->where('category', $value))
            ->when($dosageForm, fn (Builder $builder, string $value) => $builder->where('metadata->dosageForm', $value));

        $rows = $queryBuilder
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        $counts = [
            'active' => 0,
            'inactive' => 0,
            'retired' => 0,
            'other' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->status);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($status, $counts) && $status !== 'other' && $status !== 'total') {
                $counts[$status] += $aggregate;
            } else {
                $counts['other'] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    public function typeCounts(
        ?string $query,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
    ): array {
        $queryBuilder = ClinicalCatalogItemModel::query();
        $this->applyPlatformScope($queryBuilder);
        app(FacilityTierSupport::class)->applyAvailabilityFilter(
            $queryBuilder,
            'platform_clinical_catalog_items',
            app(CurrentPlatformScopeContextInterface::class)->facilityId(),
        );

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($category, fn (Builder $builder, string $value) => $builder->where('category', $value))
            ->when($dosageForm, fn (Builder $builder, string $value) => $builder->where('metadata->dosageForm', $value));

        $rows = $queryBuilder
            ->selectRaw('catalog_type, COUNT(*) as aggregate')
            ->groupBy('catalog_type')
            ->get();

        $counts = [
            'lab_test' => 0,
            'radiology_procedure' => 0,
            'theatre_procedure' => 0,
            'clinical_procedure' => 0,
            'formulary_item' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $type = strtolower((string) $row->catalog_type);
            $aggregate = (int) $row->aggregate;

            if (array_key_exists($type, $counts) && $type !== 'total') {
                $counts[$type] += $aggregate;
            }

            $counts['total'] += $aggregate;
        }

        return $counts;
    }

    /**
     * Scope a catalog query to the current facility/tenant unconditionally —
     * not gated behind platform.multi_facility_scoping /
     * platform.multi_tenant_isolation like most other repositories. Catalog
     * rows carry facility-specific pricing and consumption recipes, so a
     * cross-tenant row is never a valid choice regardless of where those
     * flags are in their rollout; without this, a facility with no scope
     * resolved (or before those flags exist) would see every other
     * tenant's catalog data. PlatformScopeQueryApplier::apply() is a no-op
     * when neither facility nor tenant scope resolves, so this stays safe
     * for contexts with no scope at all (e.g. an unscoped platform-admin
     * request).
     */
    private function applyPlatformScope(Builder $query): void
    {
        $this->platformScopeQueryApplier->apply($query);
    }

    private function applySearchFilter(Builder $query, string $searchTerm): void
    {
        $normalizedSearchTerm = mb_strtolower(trim($searchTerm));
        if ($normalizedSearchTerm === '') {
            return;
        }

        $like = '%'.$normalizedSearchTerm.'%';

        $query->where(function (Builder $nestedQuery) use ($like): void {
            $nestedQuery
                ->whereRaw('LOWER(code) LIKE ?', [$like])
                ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(category) LIKE ?', [$like])
                ->orWhereRaw('LOWER(unit) LIKE ?', [$like])
                ->orWhereRaw('LOWER(description) LIKE ?', [$like]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributesForCurrentSchema(array $attributes): array
    {
        if (! Schema::hasColumn('platform_clinical_catalog_items', 'codes')) {
            unset($attributes['codes']);
        }

        if (! Schema::hasColumn('platform_clinical_catalog_items', 'facility_tier')) {
            unset($attributes['facility_tier']);
        }

        return $attributes;
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (ClinicalCatalogItemModel $item): array => $item->toArray(),
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
