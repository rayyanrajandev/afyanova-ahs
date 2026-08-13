<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffPrivilegeGrantException;
use App\Modules\Staff\Application\Exceptions\InvalidStaffPrivilegeGrantAssignmentException;
use App\Modules\Staff\Application\Exceptions\StaffPrivilegeGrantCredentialingNotReadyException;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalPrivilegeCatalogException;
use App\Modules\Staff\Application\Exceptions\UnknownClinicalSpecialtyException;
use App\Modules\Staff\Application\Services\ClinicalPrivilegeCatalogAssignmentGuard;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffCredentialingState;
use App\Modules\Staff\Domain\ValueObjects\StaffPrivilegeGrantStatus;
use Illuminate\Support\Facades\DB;

class CreateStaffPrivilegeGrantUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
        private readonly StaffPrivilegeGrantAuditLogRepositoryInterface $auditLogRepository,
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly GetStaffCredentialingSummaryUseCase $getStaffCredentialingSummaryUseCase,
        private readonly ClinicalPrivilegeCatalogAssignmentGuard $clinicalPrivilegeCatalogAssignmentGuard,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly VerifiedStaffUserEmailGuard $verifiedStaffUserEmailGuard,
    ) {}

    public function execute(string $staffProfileId, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $this->assertCredentialingReady($staffProfileId, 'submitted');

        $tenantId = $this->resolveTenantId($profile);
        $facilityId = trim((string) ($payload['facility_id'] ?? ''));
        if (! $this->isFacilityWithinTenantScope($facilityId, $tenantId)) {
            throw new InvalidStaffPrivilegeGrantAssignmentException(
                'Selected facility is invalid or outside the current tenant scope.',
            );
        }

        $privilegeDefinition = $this->resolvePrivilegeDefinition($payload);
        if (is_array($privilegeDefinition['catalog'] ?? null)) {
            $this->clinicalPrivilegeCatalogAssignmentGuard->assertEligible(
                staffProfileId: $staffProfileId,
                facilityId: $facilityId,
                catalog: $privilegeDefinition['catalog'],
            );
        }
        $specialtyId = $privilegeDefinition['specialty_id'];
        $privilegeCode = $privilegeDefinition['privilege_code'];
        if ($this->staffPrivilegeGrantRepository->existsDuplicateInScope(
            staffProfileId: $staffProfileId,
            facilityId: $facilityId,
            specialtyId: $specialtyId,
            privilegeCode: $privilegeCode,
        )) {
            throw new DuplicateStaffPrivilegeGrantException(
                'Privilege code already exists for the selected staff/facility/specialty scope.',
            );
        }

        $created = $this->staffPrivilegeGrantRepository->create([
            'staff_profile_id' => $staffProfileId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'specialty_id' => $specialtyId,
            'privilege_catalog_id' => $privilegeDefinition['privilege_catalog_id'],
            'privilege_code' => $privilegeCode,
            'privilege_name' => $privilegeDefinition['privilege_name'],
            'scope_notes' => $this->nullableTrimmedValue($payload['scope_notes'] ?? null),
            'granted_at' => $payload['granted_at'] ?? null,
            'review_due_at' => $payload['review_due_at'] ?? null,
            'requested_at' => now(),
            'review_started_at' => null,
            'approved_at' => null,
            'activated_at' => null,
            'status' => StaffPrivilegeGrantStatus::REQUESTED->value,
            'status_reason' => null,
            'granted_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);

        $this->auditLogRepository->write(
            staffPrivilegeGrantId: (string) ($created['id'] ?? ''),
            staffProfileId: $staffProfileId,
            action: 'staff-privilege-grant.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
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

    private function assertCredentialingReady(string $staffProfileId, string $operation): void
    {
        $summary = $this->getStaffCredentialingSummaryUseCase->execute($staffProfileId);
        $state = $summary['credentialing_state'] ?? null;
        if ($state === StaffCredentialingState::READY->value) {
            return;
        }

        $reasons = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim((string) $value),
                is_array($summary['blocking_reasons'] ?? null) ? $summary['blocking_reasons'] : [],
            ),
            static fn (string $value): bool => $value !== '',
        ));

        $message = sprintf(
            'Privilege requests cannot be %s until staff credentialing is ready.',
            $operation,
        );
        if ($reasons !== []) {
            $message .= ' '.implode(' ', array_map(
                static fn (string $reason): string => rtrim($reason, '.').'.',
                $reasons,
            ));
        }

        throw new StaffPrivilegeGrantCredentialingNotReadyException($message);
    }

    /**
     * @return array{privilege_catalog_id:?string,specialty_id:string,privilege_code:string,privilege_name:string,catalog:?array<string,mixed>}
     */
    private function resolvePrivilegeDefinition(array $payload): array
    {
        $catalogId = trim((string) ($payload['privilege_catalog_id'] ?? ''));
        if ($catalogId !== '') {
            $catalog = $this->resolveActivePrivilegeCatalog($catalogId);

            return [
                'privilege_catalog_id' => (string) ($catalog['id'] ?? $catalogId),
                'specialty_id' => trim((string) ($catalog['specialty_id'] ?? '')),
                'privilege_code' => $this->normalizeCode((string) ($catalog['code'] ?? '')),
                'privilege_name' => trim((string) ($catalog['name'] ?? '')),
                'catalog' => $catalog,
            ];
        }

        $specialtyId = trim((string) ($payload['specialty_id'] ?? ''));
        $specialty = $this->clinicalSpecialtyRepository->findById($specialtyId);
        if (! $specialty) {
            throw new UnknownClinicalSpecialtyException(
                'Clinical specialty is invalid or outside the current scope.',
            );
        }

        return [
            'privilege_catalog_id' => null,
            'specialty_id' => $specialtyId,
            'privilege_code' => $this->normalizeCode((string) ($payload['privilege_code'] ?? '')),
            'privilege_name' => trim((string) ($payload['privilege_name'] ?? '')),
            'catalog' => null,
        ];
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
        $catalog = $this->clinicalPrivilegeCatalogRepository->findById($catalogId);
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
    private function extractTrackedFields(array $grant): array
    {
        $tracked = [
            'staff_profile_id',
            'tenant_id',
            'facility_id',
            'specialty_id',
            'privilege_catalog_id',
            'privilege_code',
            'privilege_name',
            'scope_notes',
            'granted_at',
            'review_due_at',
            'requested_at',
            'review_started_at',
            'approved_at',
            'activated_at',
            'status',
            'status_reason',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $grant[$field] ?? null;
        }

        return $result;
    }
}
