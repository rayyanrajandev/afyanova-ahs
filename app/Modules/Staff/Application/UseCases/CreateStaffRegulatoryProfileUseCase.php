<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Exceptions\DuplicateStaffRegulatoryProfileException;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class CreateStaffRegulatoryProfileUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
        private readonly StaffCredentialingAuditLogRepositoryInterface $auditLogRepository,
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

        if ($this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId) !== null) {
            throw new DuplicateStaffRegulatoryProfileException(
                'A regulatory profile already exists for this staff member.',
            );
        }

        $tenantId = $this->resolveTenantId($profile);
        $created = $this->staffRegulatoryProfileRepository->create([
            'staff_profile_id' => $staffProfileId,
            'tenant_id' => $tenantId,
            'primary_regulator_code' => $this->normalizeCode($payload['primary_regulator_code'] ?? null),
            'cadre_code' => trim((string) ($payload['cadre_code'] ?? '')),
            'professional_title' => trim((string) ($payload['professional_title'] ?? '')),
            'registration_type' => trim((string) ($payload['registration_type'] ?? '')),
            'practice_authority_level' => $this->normalizeCode($payload['practice_authority_level'] ?? null),
            'supervision_level' => $this->normalizeCode($payload['supervision_level'] ?? null),
            'good_standing_status' => $this->normalizeCode($payload['good_standing_status'] ?? null),
            'good_standing_checked_at' => $payload['good_standing_checked_at'] ?? null,
            'notes' => $this->nullableTrimmedValue($payload['notes'] ?? null),
            'created_by_user_id' => $actorId,
            'updated_by_user_id' => $actorId,
        ]);

        $this->auditLogRepository->write(
            staffProfileId: $staffProfileId,
            tenantId: $tenantId,
            staffRegulatoryProfileId: (string) ($created['id'] ?? ''),
            staffProfessionalRegistrationId: null,
            action: 'staff-credentialing.regulatory-profile.created',
            actorId: $actorId,
            changes: [
                'after' => $this->extractTrackedFields($created),
            ],
        );

        return $created;
    }

    private function normalizeCode(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveTenantId(array $profile): ?string
    {
        $profileTenantId = $profile['tenant_id'] ?? null;
        if (is_string($profileTenantId) && trim($profileTenantId) !== '') {
            return trim($profileTenantId);
        }

        return $this->platformScopeContext->tenantId();
    }

    /**
     * @return array<string, mixed>
     */
    private function extractTrackedFields(array $profile): array
    {
        $tracked = [
            'staff_profile_id',
            'tenant_id',
            'primary_regulator_code',
            'cadre_code',
            'professional_title',
            'registration_type',
            'practice_authority_level',
            'supervision_level',
            'good_standing_status',
            'good_standing_checked_at',
            'notes',
        ];

        $result = [];
        foreach ($tracked as $field) {
            $result[$field] = $profile[$field] ?? null;
        }

        return $result;
    }
}
