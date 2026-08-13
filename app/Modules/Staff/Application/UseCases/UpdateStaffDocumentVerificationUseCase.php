<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentVerificationStatus;

class UpdateStaffDocumentVerificationUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
        private readonly StaffDocumentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $staffProfileId,
        string $staffDocumentId,
        string $verificationStatus,
        ?string $reason,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $existing = $this->staffDocumentRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffDocumentId,
        );
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($verificationStatus, StaffDocumentVerificationStatus::values(), true)
            ? $verificationStatus
            : StaffDocumentVerificationStatus::PENDING->value;

        $normalizedReason = $this->nullableTrimmedValue($reason);

        $updatePayload = [
            'verification_status' => $normalizedStatus,
            'verification_reason' => $normalizedStatus === StaffDocumentVerificationStatus::REJECTED->value
                ? $normalizedReason
                : null,
            'verified_by_user_id' => $normalizedStatus === StaffDocumentVerificationStatus::PENDING->value
                ? null
                : $actorId,
            'verified_at' => $normalizedStatus === StaffDocumentVerificationStatus::PENDING->value
                ? null
                : now(),
        ];

        $updated = $this->staffDocumentRepository->update($staffDocumentId, $updatePayload);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [
                'transition' => [
                    'from' => $existing['verification_status'] ?? null,
                    'to' => $updated['verification_status'] ?? null,
                ],
                'reason_required' => $normalizedStatus === StaffDocumentVerificationStatus::REJECTED->value,
                'reason_provided' => $normalizedReason !== null,
            ];

            $this->auditLogRepository->write(
                staffDocumentId: $staffDocumentId,
                action: 'staff-document.verification.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: $metadata,
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
        $trackedFields = ['verification_status', 'verification_reason', 'verified_by_user_id', 'verified_at'];

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
