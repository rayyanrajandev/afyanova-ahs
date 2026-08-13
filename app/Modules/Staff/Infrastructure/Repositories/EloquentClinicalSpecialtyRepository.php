<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\ClinicalSpecialtyModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileSpecialtyModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EloquentClinicalSpecialtyRepository implements ClinicalSpecialtyRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $specialty = new ClinicalSpecialtyModel();
        $specialty->fill($attributes);
        $specialty->save();

        return $specialty->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = ClinicalSpecialtyModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $specialty = $query->find($id);

        return $specialty?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = ClinicalSpecialtyModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $specialty = $query->find($id);
        if (! $specialty) {
            return null;
        }

        $specialty->fill($attributes);
        $specialty->save();

        return $specialty->toArray();
    }

    public function existsCodeInScope(string $code, ?string $tenantId, ?string $excludeId = null): bool
    {
        $query = ClinicalSpecialtyModel::query()
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
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $sortBy = in_array($sortBy, ['code', 'name', 'status', 'created_at', 'updated_at'], true)
            ? $sortBy
            : 'name';

        $queryBuilder = ClinicalSpecialtyModel::query();
        $this->applyTenantScopeIfEnabled($queryBuilder);

        $queryBuilder
            ->when($query, function (Builder $builder, string $searchTerm): void {
                $like = '%'.$searchTerm.'%';
                $builder->where(function (Builder $nestedQuery) use ($like): void {
                    $nestedQuery
                        ->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                });
            })
            ->when($status, fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toPagedResult($paginator, static fn (ClinicalSpecialtyModel $specialty): array => $specialty->toArray());
    }

    public function resolveExistingSpecialtyIdsInScope(array $specialtyIds): array
    {
        $normalizedSpecialtyIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => is_string($value) ? trim($value) : '',
            $specialtyIds,
        ))));

        if ($normalizedSpecialtyIds === []) {
            return [];
        }

        $query = ClinicalSpecialtyModel::query()
            ->whereIn('id', $normalizedSpecialtyIds);
        $this->applyTenantScopeIfEnabled($query);

        return $query
            ->pluck('id')
            ->all();
    }

    public function listByStaffProfileId(string $staffProfileId): array
    {
        $query = DB::table('staff_profile_specialty')
            ->join('clinical_specialties', 'clinical_specialties.id', '=', 'staff_profile_specialty.specialty_id')
            ->where('staff_profile_specialty.staff_profile_id', $staffProfileId);

        $this->applyTenantScopeToSpecialtyQueryIfEnabled($query);

        return $query
            ->orderByDesc('staff_profile_specialty.is_primary')
            ->orderBy('clinical_specialties.name')
            ->get([
                'staff_profile_specialty.id',
                'staff_profile_specialty.staff_profile_id',
                'staff_profile_specialty.specialty_id',
                'staff_profile_specialty.is_primary',
                'staff_profile_specialty.created_at',
                'staff_profile_specialty.updated_at',
                'clinical_specialties.code',
                'clinical_specialties.name',
                'clinical_specialties.description',
                'clinical_specialties.status',
                'clinical_specialties.status_reason',
                'clinical_specialties.tenant_id',
            ])
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function listStaffBySpecialtyId(string $specialtyId, int $page, int $perPage): array
    {
        $query = DB::table('staff_profile_specialty')
            ->join('clinical_specialties', 'clinical_specialties.id', '=', 'staff_profile_specialty.specialty_id')
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_profile_specialty.staff_profile_id')
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id')
            ->where('staff_profile_specialty.specialty_id', $specialtyId);

        $this->applyTenantScopeToSpecialtyQueryIfEnabled($query);

        $paginator = $query
            ->orderByDesc('staff_profile_specialty.is_primary')
            ->orderBy('users.name')
            ->orderBy('staff_profiles.employee_number')
            ->paginate(
                perPage: $perPage,
                columns: [
                    'staff_profiles.id',
                    'staff_profiles.user_id',
                    'staff_profiles.employee_number',
                    'staff_profiles.department',
                    'staff_profiles.job_title',
                    'staff_profiles.employment_type',
                    'staff_profiles.status',
                    'staff_profiles.status_reason',
                    'users.name as user_name',
                    'users.email as user_email',
                    'users.email_verified_at as user_email_verified_at',
                    'staff_profile_specialty.is_primary',
                    'staff_profile_specialty.created_at as assigned_at',
                    'staff_profile_specialty.updated_at as assignment_updated_at',
                ],
                pageName: 'page',
                page: $page,
            );

        return $this->toPagedResult($paginator, static fn ($row): array => (array) $row);
    }

    public function syncStaffProfileSpecialties(string $staffProfileId, array $assignments): array
    {
        DB::transaction(function () use ($staffProfileId, $assignments): void {
            $submittedBySpecialtyId = [];
            foreach ($assignments as $assignment) {
                $specialtyId = trim((string) ($assignment['specialty_id'] ?? ''));
                if ($specialtyId === '') {
                    continue;
                }

                $submittedBySpecialtyId[$specialtyId] = [
                    'specialty_id' => $specialtyId,
                    'is_primary' => (bool) ($assignment['is_primary'] ?? false),
                ];
            }

            $submittedSpecialtyIds = array_keys($submittedBySpecialtyId);
            $existingRows = StaffProfileSpecialtyModel::query()
                ->where('staff_profile_id', $staffProfileId)
                ->get(['specialty_id']);
            $existingSpecialtyIds = $existingRows->pluck('specialty_id')->all();

            $specialtyIdsToDetach = array_values(array_diff($existingSpecialtyIds, $submittedSpecialtyIds));
            if ($specialtyIdsToDetach !== []) {
                StaffProfileSpecialtyModel::query()
                    ->where('staff_profile_id', $staffProfileId)
                    ->whereIn('specialty_id', $specialtyIdsToDetach)
                    ->delete();
            }

            $now = now();
            foreach ($submittedBySpecialtyId as $specialtyId => $assignment) {
                $existing = StaffProfileSpecialtyModel::query()
                    ->where('staff_profile_id', $staffProfileId)
                    ->where('specialty_id', $specialtyId)
                    ->first();

                if ($existing) {
                    $existing->is_primary = (bool) $assignment['is_primary'];
                    $existing->updated_at = $now;
                    $existing->save();

                    continue;
                }

                StaffProfileSpecialtyModel::query()->create([
                    'staff_profile_id' => $staffProfileId,
                    'specialty_id' => $specialtyId,
                    'is_primary' => (bool) $assignment['is_primary'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return $this->listByStaffProfileId($staffProfileId);
    }

    /**
     * @param  callable  $mapper
     */
    private function toPagedResult(LengthAwarePaginator $paginator, callable $mapper): array
    {
        return [
            'data' => array_map($mapper, $paginator->items()),
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

    private function applyTenantScopeToSpecialtyQueryIfEnabled($query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $tenantId = $this->platformScopeContext->tenantId();
        if ($tenantId !== null) {
            $query->where('clinical_specialties.tenant_id', $tenantId);
        }
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}

