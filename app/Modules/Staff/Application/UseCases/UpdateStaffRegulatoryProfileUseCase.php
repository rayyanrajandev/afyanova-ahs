<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class UpdateStaffRegulatoryProfileUseCase
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

        $existing = $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
        if (! $existing) {
            return null;
        }

        $updatePayload = [];

        if (array_key_exists('primary_regulator_code', $payload)) {
            $updatePayload['primary_regulator_code'] = $this->normalizeCode($payload['primary_regulator_code']);
        }
        if (array_key_exists('cadre_code', $payload)) {
            $updatePayload['cadre_code'] = trim((string) $payload['cadre_code']);
        }
        if (array_key_exists('professional_title', $payload)) {
            $updatePayload['professional_title'] = trim((string) $payload['professional_title']);
        }
        if (array_key_exists('registration_type', $payload)) {
            $updatePayload['registration_type'] = trim((string) $payload['registration_type']);
        }
        if (array_key_exists('practice_authority_level', $payload)) {
            $updatePayload['practice_authority_level'] = $this->normalizeCode($payload['practice_authority_level']);
        }
        if (array_key_exists('supervision_level', $payload)) {
            $updatePayload['supervision_level'] = $this->normalizeCode($payload['supervision_level']);
        }
        if (array_key_exists('good_standing_status', $payload)) {
            $updatePayload['good_standing_status'] = $this->normalizeCode($payload['good_standing_status']);
        }
        if (array_key_exists('good_standing_checked_at', $payload)) {
            $updatePayload['good_standing_checked_at'] = $payload['good_standing_checked_at'];
        }
        if (array_key_exists('notes', $payload)) {
            $updatePayload['notes'] = $this->nullableTrimmedValue($payload['notes']);
        }

        $updatePayload['updated_by_user_id'] = $actorId;

        $updated = $this->staffRegulatoryProfileRepository->update(
            (string) ($existing['id'] ?? ''),
            $updatePayload,
        );
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffProfileId: $staffProfileId,
                tenantId: $updated['tenant_id'] ?? $this->resolveTenantId($profile),
                staffRegulatoryProfileId: (string) ($updated['id'] ?? ''),
                staffProfessionalRegistrationId: null,
                action: 'staff-credentialing.regulatory-profile.updated',
                actorId: $actorId,
                changes: $changes,
            );
        }

        return $updated;
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
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
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
