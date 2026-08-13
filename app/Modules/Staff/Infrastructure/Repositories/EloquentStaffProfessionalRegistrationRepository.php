<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Staff\Application\Support\StaffCredentialingRequirementEvaluator;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffProfessionalRegistrationModel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class EloquentStaffProfessionalRegistrationRepository implements StaffProfessionalRegistrationRepositoryInterface
{
    private const DUE_SOON_DAYS = 30;

    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly StaffCredentialingRequirementEvaluator $credentialingRequirementEvaluator,
    ) {}

    public function create(array $attributes): array
    {
        $registration = new StaffProfessionalRegistrationModel();
        $registration->fill($attributes);
        $registration->save();

        return $registration->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = StaffProfessionalRegistrationModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $registration = $query->find($id);

        return $registration?->toArray();
    }

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array
    {
        $query = StaffProfessionalRegistrationModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('id', $id);
        $this->applyTenantScopeIfEnabled($query);
        $registration = $query->first();

        return $registration?->toArray();
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = StaffProfessionalRegistrationModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $registration = $query->find($id);
        if (! $registration) {
            return null;
        }

        $registration->fill($attributes);
        $registration->save();

        return $registration->toArray();
    }

    public function existsDuplicateForStaffProfile(
        string $staffProfileId,
        string $regulatorCode,
        string $registrationNumber,
        ?string $excludeId = null,
    ): bool {
        $query = StaffProfessionalRegistrationModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('regulator_code', strtolower(trim($regulatorCode)))
            ->whereRaw('LOWER(registration_number) = ?', [strtolower(trim($registrationNumber))]);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        $this->applyTenantScopeIfEnabled($query);

        return $query->exists();
    }

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $regulatorCode,
        ?string $registrationStatus,
        ?string $licenseStatus,
        ?string $verificationStatus,
        ?string $expiresFrom,
        ?string $expiresTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $sortBy = in_array($sortBy, [
            'regulator_code',
            'registration_category',
            'registration_number',
            'license_number',
            'registration_status',
            'license_status',
            'verification_status',
            'issued_at',
            'expires_at',
            'renewal_due_at',
            'created_at',
            'updated_at',
        ], true)
            ? $sortBy
            : 'expires_at';

        $query = StaffProfessionalRegistrationModel::query()
            ->where('staff_profile_id', $staffProfileId);
        $this->applyTenantScopeIfEnabled($query);

        $query
            ->when($regulatorCode, fn (Builder $builder, string $value) => $builder->where('regulator_code', $value))
            ->when($registrationStatus, fn (Builder $builder, string $value) => $builder->where('registration_status', $value))
            ->when($licenseStatus, fn (Builder $builder, string $value) => $builder->where('license_status', $value))
            ->when($verificationStatus, fn (Builder $builder, string $value) => $builder->where('verification_status', $value))
            ->when($expiresFrom, fn (Builder $builder, string $value) => $builder->where('expires_at', '>=', $value))
            ->when($expiresTo, fn (Builder $builder, string $value) => $builder->where('expires_at', '<=', $value))
            ->orderBy($sortBy, $sortDirection);

        $paginator = $query->paginate(
            perPage: $perPage,
            columns: ['*'],
            pageName: 'page',
            page: $page,
        );

        return $this->toSearchResult($paginator);
    }

    public function listAllByStaffProfileId(string $staffProfileId): array
    {
        $query = StaffProfessionalRegistrationModel::query()
            ->where('staff_profile_id', $staffProfileId)
            ->orderBy('expires_at')
            ->orderByDesc('updated_at');
        $this->applyTenantScopeIfEnabled($query);

        return array_map(
            static fn (StaffProfessionalRegistrationModel $registration): array => $registration->toArray(),
            $query->get()->all(),
        );
    }

    public function listAllByStaffProfileIds(array $staffProfileIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $staffProfileIds),
            static fn (string $value): bool => $value !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $query = StaffProfessionalRegistrationModel::query()
            ->whereIn('staff_profile_id', $ids)
            ->orderBy('staff_profile_id')
            ->orderBy('expires_at')
            ->orderByDesc('updated_at');
        $this->applyTenantScopeIfEnabled($query);

        $grouped = [];
        foreach ($query->get() as $registration) {
            $payload = $registration->toArray();
            $staffProfileId = trim((string) ($payload['staff_profile_id'] ?? ''));

            if ($staffProfileId === '') {
                continue;
            }

            $grouped[$staffProfileId] ??= [];
            $grouped[$staffProfileId][] = $payload;
        }

        return $grouped;
    }

    public function searchCredentialingAlerts(
        ?string $query,
        ?string $facilityId,
        ?string $regulatorCode,
        ?string $cadreCode,
        ?string $alertType,
        ?string $alertState,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array {
        $alerts = array_merge(
            $this->missingRegulatoryProfileAlerts($query, $facilityId, $regulatorCode, $cadreCode, $alertType, $alertState),
            $this->goodStandingAlerts($query, $facilityId, $regulatorCode, $cadreCode, $alertType, $alertState),
            $this->registrationAlerts($query, $facilityId, $regulatorCode, $cadreCode, $alertType, $alertState),
        );

        $sortKey = match ($sortBy) {
            'employeeNumber' => 'employeeNumber',
            'alertType' => 'alertType',
            'alertState' => 'alertState',
            'regulatorCode' => 'regulatorCode',
            default => 'expiresAt',
        };

        usort($alerts, function (array $left, array $right) use ($sortKey, $sortDirection): int {
            $leftValue = $left[$sortKey] ?? null;
            $rightValue = $right[$sortKey] ?? null;

            $result = match ($sortKey) {
                'expiresAt' => strcmp((string) ($leftValue ?? '9999-12-31'), (string) ($rightValue ?? '9999-12-31')),
                default => strcmp((string) ($leftValue ?? ''), (string) ($rightValue ?? '')),
            };

            if ($result === 0) {
                $result = strcmp(
                    (string) ($left['employeeNumber'] ?? $left['staffProfileId'] ?? ''),
                    (string) ($right['employeeNumber'] ?? $right['staffProfileId'] ?? ''),
                );
            }

            return $sortDirection === 'desc' ? -1 * $result : $result;
        });

        $total = count($alerts);
        $offset = ($page - 1) * $perPage;
        $data = array_values(array_slice($alerts, $offset, $perPage));

        return [
            'data' => $data,
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max((int) ceil($total / max($perPage, 1)), 1),
            ],
        ];
    }

    private function missingRegulatoryProfileAlerts(
        ?string $query,
        ?string $facilityId,
        ?string $regulatorCode,
        ?string $cadreCode,
        ?string $alertType,
        ?string $alertState,
    ): array {
        if (($alertType !== null && $alertType !== 'missing_regulatory_profile')
            || ($alertState !== null && $alertState !== 'blocked')
            || $regulatorCode !== null
            || $cadreCode !== null) {
            return [];
        }

        $builder = DB::table('staff_profiles')
            ->leftJoin('staff_regulatory_profiles', 'staff_regulatory_profiles.staff_profile_id', '=', 'staff_profiles.id')
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id')
            ->whereNull('staff_regulatory_profiles.id');

        $this->applyStaffProfileScopeIfEnabled($builder);
        $this->applyFacilityFilter($builder, $facilityId);
        $this->applyStaffSearch($builder, $query);

        $rows = $builder->get([
            'staff_profiles.id as staff_profile_id',
            'staff_profiles.tenant_id',
            'staff_profiles.employee_number',
            'staff_profiles.department',
            'staff_profiles.job_title',
            'users.name as user_name',
        ]);

        return $rows
            ->filter(fn (object $row): bool => $this->credentialingRequirementEvaluator->requiresCredentialing([
                'department' => $row->department,
                'job_title' => $row->job_title,
            ]))
            ->map(static function (object $row): array {
            return [
                'id' => sprintf('%s:%s', $row->staff_profile_id, 'missing_regulatory_profile'),
                'staffProfileId' => $row->staff_profile_id,
                'tenantId' => $row->tenant_id,
                'userName' => $row->user_name,
                'employeeNumber' => $row->employee_number,
                'department' => $row->department,
                'jobTitle' => $row->job_title,
                'regulatorCode' => null,
                'cadreCode' => null,
                'alertType' => 'missing_regulatory_profile',
                'alertState' => 'blocked',
                'summary' => 'No regulatory profile is recorded for this staff member.',
                'expiresAt' => null,
                'staffProfessionalRegistrationId' => null,
                'createdAt' => null,
            ];
            })->values()->all();
    }

    private function goodStandingAlerts(
        ?string $query,
        ?string $facilityId,
        ?string $regulatorCode,
        ?string $cadreCode,
        ?string $alertType,
        ?string $alertState,
    ): array {
        if ($alertType !== null && $alertType !== 'good_standing_risk') {
            return [];
        }

        $builder = DB::table('staff_profiles')
            ->join('staff_regulatory_profiles', 'staff_regulatory_profiles.staff_profile_id', '=', 'staff_profiles.id')
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id')
            ->whereIn('staff_regulatory_profiles.good_standing_status', ['restricted', 'withdrawn', 'pending']);

        $this->applyStaffProfileScopeIfEnabled($builder);
        $this->applyFacilityFilter($builder, $facilityId);
        $this->applyStaffSearch($builder, $query);

        if ($regulatorCode !== null) {
            $builder->where('staff_regulatory_profiles.primary_regulator_code', $regulatorCode);
        }
        if ($cadreCode !== null) {
            $builder->where('staff_regulatory_profiles.cadre_code', $cadreCode);
        }

        $rows = $builder->get([
            'staff_profiles.id as staff_profile_id',
            'staff_profiles.tenant_id',
            'staff_profiles.employee_number',
            'staff_profiles.department',
            'staff_profiles.job_title',
            'users.name as user_name',
            'staff_regulatory_profiles.id as staff_regulatory_profile_id',
            'staff_regulatory_profiles.primary_regulator_code',
            'staff_regulatory_profiles.cadre_code',
            'staff_regulatory_profiles.good_standing_status',
            'staff_regulatory_profiles.good_standing_checked_at',
        ]);

        $alerts = [];
        foreach ($rows as $row) {
            $state = $row->good_standing_status === 'pending' ? 'pending_verification' : 'blocked';
            if ($alertState !== null && $alertState !== $state) {
                continue;
            }

            $alerts[] = [
                'id' => sprintf('%s:%s', $row->staff_profile_id, 'good_standing_risk'),
                'staffProfileId' => $row->staff_profile_id,
                'tenantId' => $row->tenant_id,
                'userName' => $row->user_name,
                'employeeNumber' => $row->employee_number,
                'department' => $row->department,
                'jobTitle' => $row->job_title,
                'regulatorCode' => $row->primary_regulator_code,
                'cadreCode' => $row->cadre_code,
                'alertType' => 'good_standing_risk',
                'alertState' => $state,
                'summary' => sprintf(
                    'Good standing status is %s.',
                    str_replace('_', ' ', (string) $row->good_standing_status),
                ),
                'expiresAt' => $row->good_standing_checked_at,
                'staffProfessionalRegistrationId' => null,
                'createdAt' => null,
            ];
        }

        return $alerts;
    }

    private function registrationAlerts(
        ?string $query,
        ?string $facilityId,
        ?string $regulatorCode,
        ?string $cadreCode,
        ?string $alertType,
        ?string $alertState,
    ): array {
        $today = now()->startOfDay()->toDateString();
        $dueSoonAt = now()->startOfDay()->addDays(self::DUE_SOON_DAYS)->toDateString();

        $builder = DB::table('staff_professional_registrations')
            ->join('staff_profiles', 'staff_profiles.id', '=', 'staff_professional_registrations.staff_profile_id')
            ->leftJoin('staff_regulatory_profiles', 'staff_regulatory_profiles.staff_profile_id', '=', 'staff_profiles.id')
            ->leftJoin('users', 'users.id', '=', 'staff_profiles.user_id');

        $this->applyRegistrationScopeIfEnabled($builder);
        $this->applyFacilityFilter($builder, $facilityId);

        if ($query !== null) {
            $like = '%'.strtolower($query).'%';
            $builder->where(function (QueryBuilder $nested) use ($like): void {
                $nested
                    ->whereRaw('LOWER(staff_profiles.employee_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(staff_profiles.department) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(staff_profiles.job_title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(users.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(staff_professional_registrations.registration_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(staff_professional_registrations.license_number, \'\')) LIKE ?', [$like]);
            });
        }

        if ($regulatorCode !== null) {
            $builder->where('staff_professional_registrations.regulator_code', $regulatorCode);
        }
        if ($cadreCode !== null) {
            $builder->where('staff_regulatory_profiles.cadre_code', $cadreCode);
        }

        $rows = $builder->get([
            'staff_professional_registrations.id as registration_id',
            'staff_professional_registrations.regulator_code',
            'staff_professional_registrations.registration_number',
            'staff_professional_registrations.license_number',
            'staff_professional_registrations.registration_status',
            'staff_professional_registrations.license_status',
            'staff_professional_registrations.verification_status',
            'staff_professional_registrations.expires_at',
            'staff_professional_registrations.created_at',
            'staff_profiles.id as staff_profile_id',
            'staff_profiles.tenant_id',
            'staff_profiles.employee_number',
            'staff_profiles.department',
            'staff_profiles.job_title',
            'users.name as user_name',
            'staff_regulatory_profiles.cadre_code',
        ]);

        $alerts = [];
        foreach ($rows as $row) {
            [$resolvedType, $resolvedState, $summary] = $this->resolveRegistrationAlert(
                row: $row,
                today: $today,
                dueSoonAt: $dueSoonAt,
            );

            if ($resolvedType === null || $resolvedState === null) {
                continue;
            }

            if ($alertType !== null && $alertType !== $resolvedType) {
                continue;
            }
            if ($alertState !== null && $alertState !== $resolvedState) {
                continue;
            }

            $alerts[] = [
                'id' => sprintf('%s:%s', $row->registration_id, $resolvedType),
                'staffProfileId' => $row->staff_profile_id,
                'tenantId' => $row->tenant_id,
                'userName' => $row->user_name,
                'employeeNumber' => $row->employee_number,
                'department' => $row->department,
                'jobTitle' => $row->job_title,
                'regulatorCode' => $row->regulator_code,
                'cadreCode' => $row->cadre_code,
                'alertType' => $resolvedType,
                'alertState' => $resolvedState,
                'summary' => $summary,
                'expiresAt' => $row->expires_at,
                'staffProfessionalRegistrationId' => $row->registration_id,
                'createdAt' => $row->created_at,
            ];
        }

        return $alerts;
    }

    /**
     * @return array{0:?string,1:?string,2:string}
     */
    private function resolveRegistrationAlert(object $row, string $today, string $dueSoonAt): array
    {
        $expiresAt = is_string($row->expires_at ?? null) ? $row->expires_at : null;
        $registrationStatus = strtolower((string) ($row->registration_status ?? ''));
        $licenseStatus = strtolower((string) ($row->license_status ?? ''));
        $verificationStatus = strtolower((string) ($row->verification_status ?? ''));

        if ($licenseStatus === 'expired') {
            return ['expired_license', 'blocked', 'License status is expired.'];
        }

        if ($registrationStatus === 'expired' || ($expiresAt !== null && $expiresAt < $today)) {
            return ['expired_registration', 'blocked', 'Registration or practice record is expired.'];
        }

        $activeLooking = $registrationStatus === 'active'
            && in_array($licenseStatus, ['active', 'not_required'], true);

        if ($activeLooking && $verificationStatus === 'pending') {
            return ['verification_pending', 'pending_verification', 'Verification is still pending for an active-looking registration.'];
        }

        if ($activeLooking && $verificationStatus === 'verified'
            && $expiresAt !== null
            && $expiresAt >= $today
            && $expiresAt <= $dueSoonAt) {
            return ['due_soon', 'watch', 'Credential expiry is due soon.'];
        }

        return [null, null, ''];
    }

    private function toSearchResult(LengthAwarePaginator $paginator): array
    {
        return [
            'data' => array_map(
                static fn (StaffProfessionalRegistrationModel $registration): array => $registration->toArray(),
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

    private function applyRegistrationScopeIfEnabled(QueryBuilder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'staff_professional_registrations.tenant_id',
            facilityColumn: null,
        );
    }

    private function applyStaffProfileScopeIfEnabled(QueryBuilder $query): void
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

    private function applyFacilityFilter(QueryBuilder $query, ?string $facilityId): void
    {
        if ($facilityId === null) {
            return;
        }

        $query->whereExists(function (QueryBuilder $subquery) use ($facilityId): void {
            $subquery
                ->selectRaw('1')
                ->from('facility_user')
                ->whereColumn('facility_user.user_id', 'staff_profiles.user_id')
                ->where('facility_user.facility_id', $facilityId)
                ->where('facility_user.is_active', true);
        });
    }

    private function applyStaffSearch(QueryBuilder $query, ?string $search): void
    {
        if ($search === null) {
            return;
        }

        $like = '%'.strtolower($search).'%';

        $query->where(function (QueryBuilder $nested) use ($like): void {
            $nested
                ->whereRaw('LOWER(staff_profiles.employee_number) LIKE ?', [$like])
                ->orWhereRaw('LOWER(staff_profiles.department) LIKE ?', [$like])
                ->orWhereRaw('LOWER(staff_profiles.job_title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(COALESCE(users.name, \'\')) LIKE ?', [$like]);
        });
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
