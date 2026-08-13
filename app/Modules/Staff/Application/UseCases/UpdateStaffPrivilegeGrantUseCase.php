<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Services\ClinicalPrivilegeCatalogAssignmentGuard;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffPrivilegeGrantException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffPrivilegeGrantAssignmentException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalPrivilegeCatalogException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use Illuminate\Support\Facades\DB;

class UpdateStaffPrivilegeGrantUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
        private readonly StaffPrivilegeGrantAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly ClinicalPrivilegeCatalogAssignmentGuard $clinicalPrivilegeCatalogAssignmentGuard,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly VerifiedStaffUserEmailGuard $verifiedStaffUserEmailGuard,
    ) {}

    public function execute(
        string $staffProfileId,
        string $staffPrivilegeGrantId,
        array $payload,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $existing = $this->staffPrivilegeGrantRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffPrivilegeGrantId,
        );
        if (! $existing) {
            return null;
        }

        $tenantId = $this->resolveTenantId($profile);
        $updatePayload = [];

        if (array_key_exists('facility_id', $payload)) {
            $facilityId = trim((string) $payload['facility_id']);
            if (! $this->isFacilityWithinTenantScope($facilityId, $tenantId)) {
                throw new InvalidStaffPrivilegeGrantAssignmentException(
                    'Selected facility is invalid or outside the current tenant scope.',
                );
            }

            $updatePayload['facility_id'] = $facilityId;
        }

        $catalogForScopeValidation = null;

        if (array_key_exists('privilege_catalog_id', $payload)) {
            $catalog = $this->resolveActivePrivilegeCatalog((string) $payload['privilege_catalog_id']);
            $updatePayload['privilege_catalog_id'] = (string) ($catalog['id'] ?? '');
            $updatePayload['specialty_id'] = trim((string) ($catalog['specialty_id'] ?? ''));
            $updatePayload['privilege_code'] = $this->normalizeCode((string) ($catalog['code'] ?? ''));
            $updatePayload['privilege_name'] = trim((string) ($catalog['name'] ?? ''));
            $catalogForScopeValidation = $catalog;
        } elseif ($this->shouldDetachCatalog($payload) && ! empty($existing['privilege_catalog_id'])) {
            $updatePayload['privilege_catalog_id'] = null;
        }

        if (! array_key_exists('privilege_catalog_id', $payload) && array_key_exists('specialty_id', $payload)) {
            $specialtyId = trim((string) $payload['specialty_id']);
            $specialty = $this->clinicalSpecialtyRepository->findById($specialtyId);
            if (! $specialty) {
                throw new UnknownClinicalSpecialtyException(
                    'Clinical specialty is invalid or outside the current scope.',
                );
            }

            $updatePayload['specialty_id'] = $specialtyId;
        }

        if (! array_key_exists('privilege_catalog_id', $payload) && array_key_exists('privilege_code', $payload)) {
            $updatePayload['privilege_code'] = $this->normalizeCode((string) $payload['privilege_code']);
        }

        if (! array_key_exists('privilege_catalog_id', $payload) && array_key_exists('privilege_name', $payload)) {
            $updatePayload['privilege_name'] = trim((string) $payload['privilege_name']);
        }

        if (array_key_exists('scope_notes', $payload)) {
            $updatePayload['scope_notes'] = $this->nullableTrimmedValue($payload['scope_notes']);
        }

        if (array_key_exists('granted_at', $payload)) {
            $updatePayload['granted_at'] = $payload['granted_at'];
        }

        if (array_key_exists('review_due_at', $payload)) {
            $updatePayload['review_due_at'] = $payload['review_due_at'];
        }

        $nextFacilityId = (string) ($updatePayload['facility_id'] ?? ($existing['facility_id'] ?? ''));
        $nextSpecialtyId = (string) ($updatePayload['specialty_id'] ?? ($existing['specialty_id'] ?? ''));
        $nextPrivilegeCode = (string) ($updatePayload['privilege_code'] ?? ($existing['privilege_code'] ?? ''));

        if (
            $catalogForScopeValidation === null
            && array_key_exists('facility_id', $payload)
            && ! array_key_exists('privilege_catalog_id', $payload)
            && ! empty($existing['privilege_catalog_id'])
        ) {
            $catalogForScopeValidation = $this->clinicalPrivilegeCatalogRepository->findById(
                (string) $existing['privilege_catalog_id'],
            );
        }

        if (is_array($catalogForScopeValidation)) {
            $this->clinicalPrivilegeCatalogAssignmentGuard->assertEligible(
                staffProfileId: $staffProfileId,
                facilityId: $nextFacilityId,
                catalog: $catalogForScopeValidation,
            );
        }

        if ($this->staffPrivilegeGrantRepository->existsDuplicateInScope(
            staffProfileId: $staffProfileId,
            facilityId: $nextFacilityId,
            specialtyId: $nextSpecialtyId,
            privilegeCode: $nextPrivilegeCode,
            excludeId: $staffPrivilegeGrantId,
        )) {
            throw new DuplicateStaffPrivilegeGrantException(
                'Privilege code already exists for the selected staff/facility/specialty scope.',
            );
        }

        $updatePayload['updated_by_user_id'] = $actorId;

        $updated = $this->staffPrivilegeGrantRepository->update($staffPrivilegeGrantId, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffPrivilegeGrantId: $staffPrivilegeGrantId,
                staffProfileId: $staffProfileId,
                action: 'staff-privilege-grant.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
    }

    private function normalizeCode(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function shouldDetachCatalog(array $payload): bool
    {
        return array_key_exists('specialty_id', $payload)
            || array_key_exists('privilege_code', $payload)
            || array_key_exists('privilege_name', $payload);
    }

    private function resolveTenantId(array $profile): ?string
    {
        $profileTenantId = $profile['tenant_id'] ?? null;
        if (is_string($profileTenantId) && trim($profileTenantId) !== '') {
            return trim($profileTenantId);
        }

        return $this->platformScopeContext->tenantId();
    }

    private function isFacilityWithinTenantScope(string $facilityId, ?string $tenantId): bool
    {
        if ($facilityId === '') {
            return false;
        }

        $query = DB::table('facilities')
            ->where('id', $facilityId);

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query->exists();
    }

    private function resolveActivePrivilegeCatalog(string $catalogId): array
    {
        $catalog = $this->clinicalPrivilegeCatalogRepository->findById(trim($catalogId));
        if (! $catalog || strtolower((string) ($catalog['status'] ?? '')) !== 'active') {
            throw new UnknownClinicalPrivilegeCatalogException(
                'Privilege template is invalid, inactive, or outside the current scope.',
            );
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'facility_id',
            'specialty_id',
            'privilege_catalog_id',
            'privilege_code',
            'privilege_name',
            'scope_notes',
            'granted_at',
            'review_due_at',
        ];

        $changes = [];
        foreach ($trackedFields as $field) {
            $beforeValue = $before[$field] ?? null;
            $afterValue = $after[$field] ?? null;
            if ($beforeValue === $afterValue) {
                continue;
            }

            $changes[$field] = [
                'before' => $beforeValue,
                'after' => $afterValue,
            ];
        }

        return $changes;
    }
}
