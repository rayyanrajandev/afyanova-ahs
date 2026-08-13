<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\ClinicalPrivilegeCatalogModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentClinicalPrivilegeCatalogRepository implements ClinicalPrivilegeCatalogRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function findById(string $id): ?array
    {
        $query = ClinicalPrivilegeCatalogModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $catalog = $query->find($id);

        return $catalog?->toArray();
    }

    public function create(array $attributes): array
    {
        $catalog = new ClinicalPrivilegeCatalogModel();
        $catalog->fill($attributes);
        $catalog->save();

        return $catalog->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ClinicalPrivilegeCatalogModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $catalog = $query->find($id);
        if (! $catalog) {
            return null;
        }

        $catalog->fill($attributes);
        $catalog->save();

        return $catalog->toArray();
    }

    public function existsCodeInScope(string $code, ?string $tenantId, ?string $excludeId = null): bool
    {
        $query = ClinicalPrivilegeCatalogModel::query()
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))]);

        if ($tenantId === null) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $tenantId);
        }

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $specialtyId,
        ?string $cadreCode,
        ?string $facilityType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = ClinicalPrivilegeCatalogModel::query();
        $this->applyTenantScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('cadre_code', 'like', $like)
                        ->orWhere('facility_type', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($specialtyId, fn (Builder $builder, string $value) => $builder->where('specialty_id', $value))
            ->when($cadreCode, fn (Builder $builder, string $value) => $builder->where('cadre_code', $value))
            ->when($facilityType, fn (Builder $builder, string $value) => $builder->where('facility_type', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
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
                static fn (ClinicalPrivilegeCatalogModel $catalog): array => $catalog->toArray(),
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

    private function applyTenantScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'tenant_id',
            facilityColumn: null,
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
