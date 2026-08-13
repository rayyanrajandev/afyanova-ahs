<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffPrivilegeGrantModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentStaffPrivilegeGrantRepository implements StaffPrivilegeGrantRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $grant = new StaffPrivilegeGrantModel();
        $grant->fill($attributes);
        $grant->save();

        return $grant->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = StaffPrivilegeGrantModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $grant = $query->find($id);

        return $grant?->toArray();
    }

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array
    {
        $query = StaffPrivilegeGrantModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('id', $id);
        $this->applyPlatformScopeIfEnabled($query);
        $grant = $query->first();

        return $grant?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = StaffPrivilegeGrantModel::query();
        $this->applyPlatformScopeIfEnabled($query);
        $grant = $query->find($id);
        if (! $grant) {
            return null;
        }

        $grant->fill($attributes);
        $grant->save();

        return $grant->toArray();
    }

    public function existsDuplicateInScope(
        string $staffProfileId,
        string $facilityId,
        string $specialtyId,
        string $privilegeCode,
        ?string $excludeId = null
    ): bool {
        $query = StaffPrivilegeGrantModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('facility_id', $facilityId)
            ->where('specialty_id', $specialtyId)
            ->whereRaw('LOWER(privilege_code) = ?', [strtolower(trim($privilegeCode))]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $this->applyPlatformScopeIfEnabled($query);

        return $query->exists();
    }

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $query,
        ?string $facilityId,
        ?string $specialtyId,
        ?string $status,
        ?string $grantedFrom,
        ?string $grantedTo,
        ?string $reviewDueFrom,
        ?string $reviewDueTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array {
        $sortBy = in_array($sortBy, [
            'privilege_code',
            'privilege_name',
            'facility_id',
            'specialty_id',
            'granted_at',
            'review_due_at',
            'status',
            'created_at',
            'updated_at',
        ], true)
            ? $sortBy
            : 'granted_at';

        $queryBuilder = StaffPrivilegeGrantModel::query()
            ->where('staff_profile_id', $staffProfileId);
        $this->applyPlatformScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('privilege_code', 'like', $like)
                        ->orWhere('privilege_name', 'like', $like)
                        ->orWhere('scope_notes', 'like', $like)
                        ->orWhere('status_reason', 'like', $like);
                });
            })
            ->when($facilityId, fn (Builder $builder, string $value) => $builder->where('facility_id', $value))
            ->when($specialtyId, fn (Builder $builder, string $value) => $builder->where('specialty_id', $value))
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->when($grantedFrom, fn (Builder $builder, string $value) => $builder->where('granted_at', '>=', $value))
            ->when($grantedTo, fn (Builder $builder, string $value) => $builder->where('granted_at', '<=', $value))
            ->when($reviewDueFrom, fn (Builder $builder, string $value) => $builder->where('review_due_at', '>=', $value))
            ->when($reviewDueTo, fn (Builder $builder, string $value) => $builder->where('review_due_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function listByStaffProfileIds(array $staffProfileIds, ?string $status = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $staffProfileIds),
            static fn (string $value): bool => $value !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $query = StaffPrivilegeGrantModel::query()
            ->whereIn('staff_profile_id', $ids)
            ->orderBy('staff_profile_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at');
        $this->applyPlatformScopeIfEnabled($query);

        if ($status !== null) {
            $query->where('status', $status);
        }

        $grouped = [];
        foreach ($query->get() as $grant) {
            $payload = $grant->toArray();
            $staffProfileId = trim((string) ($payload['staff_profile_id'] ?? ''));

            if ($staffProfileId === '') {
                continue;
            }

            $grouped[$staffProfileId] ??= [];
            $grouped[$staffProfileId][] = $payload;
        }

        return $grouped;
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (StaffPrivilegeGrantModel $grant): array => $grant->toArray(),
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

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'tenant_id',
            facilityColumn: 'facility_id',
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
