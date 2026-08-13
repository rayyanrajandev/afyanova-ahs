<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentFacilityConfigurationRepository implements FacilityConfigurationRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function findById(string $id): ?array
    {
        $query = FacilityModel::query()->with($this->ownerRelations());
        $this->applyPlatformScopeIfEnabled($query);

        $facility = $query->find($id);

        return $facility?->toArray();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $facilityType,
        ?int $ownerUserId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'facility_type', 'timezone', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = FacilityModel::query()->with($this->ownerRelations());
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('facility_type', 'like', $like)
                        ->orWhere('timezone', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($facilityType, fn (Builder $builder, string $value) => $builder->where('facility_type', $value))
            ->when($ownerUserId !== null, function (Builder $builder) use ($ownerUserId): void {
                $builder->where(function (Builder $nestedQuery) use ($ownerUserId): void {
                    $nestedQuery
                        ->where('operations_owner_user_id', $ownerUserId)
                        ->orWhere('clinical_owner_user_id', $ownerUserId)
                        ->orWhere('administrative_owner_user_id', $ownerUserId);
                });
            })
            ->orderBy($sortBy, $sortDirection)
            ->orderBy('id');

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function create(array $attributes): array
    {
        $facility = FacilityModel::query()->create($attributes);
        $facility->load($this->ownerRelations());

        return $facility->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = FacilityModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $facility = $query->find($id);
        if (! $facility) {
            return null;
        }

        $facility->fill($attributes);
        $facility->save();
        $facility->load($this->ownerRelations());

        return $facility->toArray();
    }

    public function existsCodeInTenant(
        string $tenantId,
        string $code,
        ?string $excludeId = null
    ): bool {
        $query = FacilityModel::query()
            ->where('tenant_id', $tenantId)
            ->whereRaw('LOWER(code) = ?', [strtolower(trim($code))]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query, tenantColumn: 'tenant_id', facilityColumn: 'id');
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
                static fn (FacilityModel $facility): array => $facility->toArray(),
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

    /**
     * @return array<int, string>
     */
    private function ownerRelations(): array
    {
        return ['operationsOwner', 'clinicalOwner', 'administrativeOwner'];
    }
}
