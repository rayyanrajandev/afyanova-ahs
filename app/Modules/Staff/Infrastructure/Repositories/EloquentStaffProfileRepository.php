<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class EloquentStaffProfileRepository implements StaffProfileRepositoryInterface
{
    private const PROFILE_IDENTITY_COLUMNS = [
        'staff_profiles.*',
        'users.name as user_name',
        'users.email as user_email',
        'users.email_verified_at as user_email_verified_at',
    ];
    private const CLINICAL_ROLE_KEYWORDS = [
        'doctor',
        'surgeon',
        'medical officer',
        'clinical officer',
        'anaesthetist',
        'anesthetist',
        'nurse',
        'midwife',
        'laboratory',
        'lab',
        'radiographer',
        'radiology',
        'pharmacist',
        'pharmacy',
        'theatre',
        'recovery',
        'triage',
        'emergency',
        'ward',
        'dispensary',
        'maternity',
        'clinic',
        'outpatient',
        'inpatient',
        'sonographer',
        'physiotherapist',
        'dentist',
    ];
    /**
     * A narrower subset of CLINICAL_ROLE_KEYWORDS, added for the appointment
     * scheduling clinician picker (AppointmentCreateSheet.vue/
     * AppointmentEditSheet.vue) — that field previously reused GET
     * /staff/clinical-directory's clinicalOnly=true filter as-is, which
     * deliberately includes lab/pharmacy/radiology/theatre staff (correct
     * for that endpoint's original triage/referral-routing purpose, where
     * any clinical-tier staff member is a legitimate target). An
     * appointment's clinician is specifically the provider holding the
     * consultation, so this excludes those non-physician clinical roles.
     * Applied as an ADDITIONAL filter alongside clinicalOnly, not a
     * replacement for it — every other clinicalOnly consumer is unaffected.
     */
    private const PHYSICIAN_ROLE_KEYWORDS = [
        'doctor',
        'surgeon',
        'medical officer',
        'clinical officer',
        'anaesthetist',
        'anesthetist',
        'dentist',
    ];
    private const SUPPORT_ROLE_KEYWORDS = [
        'medical records',
        'records officer',
        'registration',
        'front desk',
        'cashier',
        'billing',
        'finance',
        'account',
        'admin',
        'administrator',
        'secretary',
        'reception',
        'procurement',
        'supply',
        'storekeeper',
    ];

    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $profile = new StaffProfileModel();
        $profile->fill($attributes);
        $profile->save();

        return $this->findById((string) $profile->getKey()) ?? $profile->toArray();
    }

    public function findById(string $id): ?array
    {
        $profile = $this->profileIdentityQuery()
            ->where('staff_profiles.id', $id)
            ->first();

        return $this->toProfileArray($profile);
    }

    public function findByIds(array $ids): array
    {
        $normalizedIds = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $ids),
            static fn (string $value): bool => $value !== '',
        )));

        if ($normalizedIds === []) {
            return [];
        }

        $profiles = $this->profileIdentityQuery()
            ->whereIn('staff_profiles.id', $normalizedIds)
            ->get();

        $byId = [];
        foreach ($profiles as $profile) {
            $payload = $this->toProfileArray($profile);
            $id = trim((string) ($payload['id'] ?? ''));

            if ($id === '') {
                continue;
            }

            $byId[$id] = $payload;
        }

        $ordered = [];
        foreach ($normalizedIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = StaffProfileModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $profile = $query->find($id);
        if (! $profile) {
            return null;
        }

        $profile->fill($attributes);
        $profile->save();

        return $this->findById($id) ?? $profile->toArray();
    }

    public function existsByEmployeeNumber(string $employeeNumber): bool
    {
        return StaffProfileModel::query()
            ->where('employee_number', $employeeNumber)
            ->exists();
    }

    public function findByUserId(string $userId): ?array
    {
        $profile = $this->profileIdentityQuery()
            ->where('staff_profiles.user_id', (int) $userId)
            ->first();

        return $this->toProfileArray($profile);
    }

    public function listDistinctDepartments(): array
    {
        $query = StaffProfileModel::query()
            ->select('department')
            ->whereNotNull('department')
            ->whereRaw("TRIM(COALESCE(department, '')) != ''")
            ->distinct()
            ->orderBy('department');
        $this->applyTenantScopeIfEnabled($query);

        return $query
            ->pluck('department')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->values()
            ->all();
    }

    public function search(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        bool $clinicalOnly,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        bool $physicianOnly = false
    ): array {
        $queryBuilder = $this->buildSearchQuery(
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            clinicalOnly: $clinicalOnly,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            physicianOnly: $physicianOnly,
        );

        $paginator = $queryBuilder->paginate(
            perPage: $perPage,
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function locateInSearch(
        string $staffProfileId,
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        bool $clinicalOnly,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): ?array {
        $normalizedId = trim($staffProfileId);
        if ($normalizedId === '') {
            return null;
        }

        $orderedIds = $this->buildSearchQuery(
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            clinicalOnly: $clinicalOnly,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        )
            ->select('staff_profiles.id')
            ->pluck('staff_profiles.id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->values()
            ->all();

        $position = array_search($normalizedId, $orderedIds, true);
        if ($position === false) {
            return null;
        }

        $safePerPage = max($perPage, 1);

        return [
            'page' => (int) floor($position / $safePerPage) + 1,
            'position' => $position + 1,
        ];
    }

    public function statusCounts(
        ?string $query,
        ?string $department,
        ?string $employmentType
    ): array {
        $queryBuilder = $this->profileQueryWithUserJoin();

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $this->applyDepartmentFilter($builder, $requestedDepartment))
            ->when($employmentType, fn (Builder $builder, string $requestedEmploymentType) => $builder->where('staff_profiles.employment_type', $requestedEmploymentType));

        $rows = $queryBuilder
            ->selectRaw('staff_profiles.status as status, COUNT(*) as aggregate')
            ->groupBy('staff_profiles.status')
            ->get();

        $counts = [
            'active' => 0,
            'suspended' => 0,
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

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                fn (StaffProfileModel $profile): array => $this->toProfileArray($profile) ?? $profile->toArray(),
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
            tenantColumn: 'staff_profiles.tenant_id',
            facilityColumn: null,
        );
    }

    private function profileIdentityQuery(): Builder
    {
        return $this->profileQueryWithUserJoin()
            ->select(...self::PROFILE_IDENTITY_COLUMNS)
            ->selectSub($this->primarySpecialtySubquery('clinical_specialties.id'), 'primary_specialty_id')
            ->selectSub($this->primarySpecialtySubquery('clinical_specialties.code'), 'primary_specialty_code')
            ->selectSub($this->primarySpecialtySubquery('clinical_specialties.name'), 'primary_specialty_name');
    }

    private function buildSearchQuery(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        bool $clinicalOnly,
        ?string $sortBy,
        string $sortDirection,
        bool $physicianOnly = false
    ): Builder {
        $queryBuilder = $this->profileIdentityQuery();

        $queryBuilder
            ->when($query, fn (Builder $builder, string $searchTerm) => $this->applySearchFilter($builder, $searchTerm))
            ->when($status, fn (Builder $builder, string $requestedStatus) => $builder->where('staff_profiles.status', $requestedStatus))
            ->when($department, fn (Builder $builder, string $requestedDepartment) => $this->applyDepartmentFilter($builder, $requestedDepartment))
            ->when($employmentType, fn (Builder $builder, string $requestedEmploymentType) => $builder->where('staff_profiles.employment_type', $requestedEmploymentType))
            ->when($clinicalOnly, fn (Builder $builder) => $this->applyClinicalRoleFilter($builder))
            ->when($physicianOnly, fn (Builder $builder) => $this->applyPhysicianRoleFilter($builder))
            ->orderBy($this->resolveSortColumn($sortBy), $sortDirection)
            ->orderBy('staff_profiles.id', $sortDirection);

        return $queryBuilder;
    }

    private function primarySpecialtySubquery(string $column): QueryBuilder
    {
        return DB::table('staff_profile_specialty')
            ->leftJoin('clinical_specialties', 'clinical_specialties.id', '=', 'staff_profile_specialty.specialty_id')
            ->whereColumn('staff_profile_specialty.staff_profile_id', 'staff_profiles.id')
            ->orderByDesc('staff_profile_specialty.is_primary')
            ->orderBy('staff_profile_specialty.created_at')
            ->limit(1)
            ->select($column);
    }

    private function profileQueryWithUserJoin(): Builder
    {
        $query = StaffProfileModel::query()
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id');
        $this->applyTenantScopeIfEnabled($query);

        return $query;
    }

    private function applySearchFilter(Builder $query, string $searchTerm): void
    {
        $like = '%'.strtolower($searchTerm).'%';

        $query->where(function (Builder $nestedQuery) use ($like): void {
            $nestedQuery
                ->whereRaw('LOWER(staff_profiles.employee_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(staff_profiles.department) LIKE ?', [$like])
                ->orWhereRaw('LOWER(staff_profiles.job_title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(staff_profiles.professional_license_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(users.name) LIKE ?', [$like]);
        });
    }

    private function applyDepartmentFilter(Builder $query, string $requestedDepartment): void
    {
        $normalized = strtolower(trim($requestedDepartment));

        if ($normalized === '') {
            return;
        }

        $query->whereRaw("LOWER(TRIM(COALESCE(staff_profiles.department, ''))) = ?", [$normalized]);
    }

    private function resolveSortColumn(?string $sortBy): string
    {
        $sortMap = [
            'employee_number' => 'staff_profiles.employee_number',
            'department' => 'staff_profiles.department',
            'job_title' => 'staff_profiles.job_title',
            'status' => 'staff_profiles.status',
            'created_at' => 'staff_profiles.created_at',
            'updated_at' => 'staff_profiles.updated_at',
        ];

        return $sortMap[$sortBy ?? 'employee_number'] ?? 'staff_profiles.employee_number';
    }

    private function applyClinicalRoleFilter(Builder $query): void
    {
        $query
            ->where(function (Builder $includeQuery): void {
                foreach (self::CLINICAL_ROLE_KEYWORDS as $index => $keyword) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $like = '%'.strtolower($keyword).'%';
                    $includeQuery->{$method}(function (Builder $matchQuery) use ($like): void {
                        $matchQuery
                            ->whereRaw("LOWER(COALESCE(staff_profiles.job_title, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(staff_profiles.department, '')) LIKE ?", [$like]);
                    });
                }
            })
            ->where(function (Builder $excludeQuery): void {
                foreach (self::SUPPORT_ROLE_KEYWORDS as $keyword) {
                    $like = '%'.strtolower($keyword).'%';
                    $excludeQuery
                        ->whereRaw("LOWER(COALESCE(staff_profiles.job_title, '')) NOT LIKE ?", [$like])
                        ->whereRaw("LOWER(COALESCE(staff_profiles.department, '')) NOT LIKE ?", [$like]);
                }
            });
    }

    private function applyPhysicianRoleFilter(Builder $query): void
    {
        $query->where(function (Builder $includeQuery): void {
            foreach (self::PHYSICIAN_ROLE_KEYWORDS as $index => $keyword) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $like = '%'.strtolower($keyword).'%';
                $includeQuery->{$method}(function (Builder $matchQuery) use ($like): void {
                    $matchQuery
                        ->whereRaw("LOWER(COALESCE(staff_profiles.job_title, '')) LIKE ?", [$like])
                        ->orWhereRaw("LOWER(COALESCE(staff_profiles.department, '')) LIKE ?", [$like]);
                });
            }
        });
    }

    private function toProfileArray(?Model $profile): ?array
    {
        if (! $profile) {
            return null;
        }

        return $profile->toArray();
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
