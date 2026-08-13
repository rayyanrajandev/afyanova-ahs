<?php

namespace App\Modules\Department\Infrastructure\Repositories;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentDepartmentRepository implements DepartmentRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $department = new DepartmentModel();
        $department->fill($attributes);
        $department->save();

        return $department->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $department = $query->find($id);

        return $department?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $department = $query->find($id);
        if (! $department) {
            return null;
        }

        $department->fill($attributes);
        $department->save();

        return $department->toArray();
    }

    public function existsByCodeInScope(
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool {
        $query = DepartmentModel::query()
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
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?int $managerUserId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'service_type', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('service_type', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('status', $requestedStatus))
            ->when($serviceType, fn (Builder $builder, string $requestedServiceType) => $builder->where('service_type', $requestedServiceType))
            ->when($managerUserId !== null, fn (Builder $builder) => $builder->where('manager_user_id', $managerUserId))
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
        ?string $query,
        ?string $serviceType,
        ?int $managerUserId
    ): array {
        $queryBuilder = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('service_type', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($serviceType, fn (Builder $builder, string $requestedServiceType) => $builder->where('service_type', $requestedServiceType))
            ->when($managerUserId !== null, fn (Builder $builder) => $builder->where('manager_user_id', $managerUserId));

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

    public function listAppointmentableOptions(): array
    {
        $query = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        return $query
            ->where('status', 'active')
            ->where('is_patient_facing', true)
            ->where('is_appointmentable', true)
            ->orderBy('name')
            ->get()
            ->map(static fn (DepartmentModel $department): array => $department->toArray())
            ->all();
    }

    public function findActiveByName(string $name): ?array
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return null;
        }

        $query = DepartmentModel::query();
        $this->applyPlatformScopeIfEnabled($query);

        $department = $query
            ->where('status', 'active')
            ->whereRaw('LOWER(name) = ?', [strtolower($trimmed)])
            ->first();

        return $department?->toArray();
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (DepartmentModel $department): array => $department->toArray(),
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

