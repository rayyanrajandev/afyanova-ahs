<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentFacilityResourceRepository implements FacilityResourceRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $resource = new FacilityResourceModel();
        $resource->fill($attributes);
        $resource->save();

        return $resource->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = FacilityResourceModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $resource = $query->find($id);

        return $resource?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = FacilityResourceModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $resource = $query->find($id);
        if (! $resource) {
            return null;
        }

        $resource->fill($attributes);
        $resource->save();

        return $resource->toArray();
    }

    public function existsByCodeInScope(
        string $resourceType,
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = FacilityResourceModel::query()
            ->where('resource_type', $resourceType)
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

    public function search(
        string $resourceType,
        ?string $query,
        ?string $status,
        ?string $departmentId,
        ?string $subtype,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = FacilityResourceModel::query()
            ->where('resource_type', $resourceType);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $builder->where(fn (Builder $nestedQuery) => $this->applyCaseInsensitiveSearch($nestedQuery, $searchTerm)))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($subtype && $resourceType === 'service_point', fn (Builder $builder, string $value) => $builder->where('service_point_type', $value))
            ->when($subtype && in_array($resourceType, ['ward_bed', 'observation_room'], true), fn (Builder $builder, string $value) => $builder->where('ward_name', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function searchAcrossResourceTypes(
        array $resourceTypes,
        ?string $query,
        ?string $status,
        ?string $departmentId,
        ?string $subtype,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = FacilityResourceModel::query()
            ->whereIn('resource_type', $resourceTypes);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $builder->where(fn (Builder $nestedQuery) => $this->applyCaseInsensitiveSearch($nestedQuery, $searchTerm)))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($subtype, fn (Builder $builder, string $value) => $builder->where('ward_name', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function statusCounts(
        string $resourceType,
        ?string $query,
        ?string $departmentId,
        ?string $subtype
    ): array {
        $queryBuilder = FacilityResourceModel::query()
            ->where('resource_type', $resourceType);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $builder->where(fn (Builder $nestedQuery) => $this->applyCaseInsensitiveSearch($nestedQuery, $searchTerm)))
            ->when($departmentId, fn (Builder $builder, string $value) => $builder->where('department_id', $value))
            ->when($subtype && $resourceType === 'service_point', fn (Builder $builder, string $value) => $builder->where('service_point_type', $value))
            ->when($subtype && in_array($resourceType, ['ward_bed', 'observation_room'], true), fn (Builder $builder, string $value) => $builder->where('ward_name', $value));

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

    public function activeWardBedExistsInScope(
        string $wardName,
        string $bedNumber,
        ?string $tenantId,
        ?string $facilityId
    ): bool {
        $normalizedWard = trim($wardName);
        $normalizedBed = trim($bedNumber);

        if ($normalizedWard === '' || $normalizedBed === '') {
            return false;
        }

        $query = FacilityResourceModel::query()
            ->where('resource_type', FacilityResourceType::WARD_BED->value)
            ->where('status', 'active')
            ->whereRaw("LOWER(TRIM(COALESCE(ward_name, ''))) = ?", [strtolower($normalizedWard)])
            ->whereRaw("LOWER(TRIM(COALESCE(bed_number, ''))) = ?", [strtolower($normalizedBed)]);

        if ($tenantId !== null || $facilityId !== null) {
            $query->where(function (Builder $builder) use ($tenantId, $facilityId): void {
                $builder->where(function (Builder $scoped) use ($tenantId, $facilityId): void {
                    if ($tenantId === null) {
                        $scoped->whereNull('tenant_id');
                    } else {
                        $scoped->where('tenant_id', $tenantId);
                    }

                    if ($facilityId === null) {
                        $scoped->whereNull('facility_id');
                    } else {
                        $scoped->where('facility_id', $facilityId);
                    }
                })->orWhere(function (Builder $global): void {
                    $global
                        ->whereNull('tenant_id')
                        ->whereNull('facility_id');
                });
            });
        }

        return $query->exists();
    }

    /**
     * Postgres's `LIKE` is case-sensitive (unlike MySQL/SQLite's default
     * collation), so a plain `where('name', 'like', "%{$term}%")` silently
     * missed "Dental Recovery" for a "dental" search in production — the
     * dev-DB test suite runs on SQLite, where that bug is invisible.
     * whereRaw + LOWER() on both sides is portable across all three and
     * matches the existing case-insensitive precedent in this same file
     * (activeWardBedExistsInScope() below).
     */
    private function applyCaseInsensitiveSearch(Builder $query, string $searchTerm): Builder
    {
        $like = '%'.strtolower($searchTerm).'%';

        return $query
            ->whereRaw('LOWER(code) LIKE ?', [$like])
            ->orWhereRaw('LOWER(name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(service_point_type) LIKE ?', [$like])
            ->orWhereRaw('LOWER(ward_name) LIKE ?', [$like])
            ->orWhereRaw('LOWER(bed_number) LIKE ?', [$like])
            ->orWhereRaw('LOWER(location) LIKE ?', [$like]);
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
                static fn (FacilityResourceModel $resource): array => $resource->toArray(),
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

