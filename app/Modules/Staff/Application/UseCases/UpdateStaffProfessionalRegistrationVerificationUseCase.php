<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Application\Services\VerifiedStaffUserEmailGuard;
use App\Modules\Staff\Domain\Repositories\StaffCredentialingAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfessionalRegistrationVerificationStatus;

class UpdateStaffProfessionalRegistrationVerificationUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
        private readonly StaffCredentialingAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly VerifiedStaffUserEmailGuard $verifiedStaffUserEmailGuard,
    ) {}

    public function execute(
        string $staffProfileId,
        string $staffProfessionalRegistrationId,
        string $verificationStatus,
        ?string $reason,
        ?string $verificationNotes,
        bool $hasVerificationNotes = false,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }
        $this->verifiedStaffUserEmailGuard->assertVerified($profile);

        $existing = $this->staffProfessionalRegistrationRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffProfessionalRegistrationId,
        );
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($verificationStatus, StaffProfessionalRegistrationVerificationStatus::values(), true)
            ? $verificationStatus
            : StaffProfessionalRegistrationVerificationStatus::PENDING->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);
        $normalizedNotes = $this->nullableTrimmedValue($verificationNotes);

        $updatePayload = [
            'verification_status' => $normalizedStatus,
            'verification_reason' => $normalizedStatus === StaffProfessionalRegistrationVerificationStatus::REJECTED->value
                ? $normalizedReason
                : null,
            'verified_by_user_id' => $normalizedStatus === StaffProfessionalRegistrationVerificationStatus::PENDING->value
                ? null
                : $actorId,
            'verified_at' => $normalizedStatus === StaffProfessionalRegistrationVerificationStatus::PENDING->value
                ? null
                : now(),
            'updated_by_user_id' => $actorId,
        ];

        if ($hasVerificationNotes) {
            $updatePayload['verification_notes'] = $normalizedNotes;
        }

        $updated = $this->staffProfessionalRegistrationRepository->update(
            $staffProfessionalRegistrationId,
            $updatePayload,
        );
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $this->auditLogRepository->write(
                staffProfileId: $staffProfileId,
                tenantId: $updated['tenant_id'] ?? ($profile['tenant_id'] ?? null),
                staffRegulatoryProfileId: $updated['staff_regulatory_profile_id'] ?? null,
                staffProfessionalRegistrationId: $staffProfessionalRegistrationId,
                action: 'staff-credentialing.registration.verification.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'transition' => [
                        'from' => $existing['verification_status'] ?? null,
                        'to' => $updated['verification_status'] ?? null,
                    ],
                    'reason_required' => $normalizedStatus === StaffProfessionalRegistrationVerificationStatus::REJECTED->value,
                    'reason_provided' => $normalizedReason !== null,
                    'notes_provided' => $hasVerificationNotes && $normalizedNotes !== null,
                ],
            );
        }

        return $updated;
    }

    private function nullableTrimmedValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'verification_status',
            'verification_reason',
            'verification_notes',
            'verified_by_user_id',
            'verified_at',
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
