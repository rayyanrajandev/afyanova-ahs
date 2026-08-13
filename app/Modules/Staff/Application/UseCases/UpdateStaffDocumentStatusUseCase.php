<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffDocumentStatus;

class UpdateStaffDocumentStatusUseCase
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
        string $status,
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

        $normalizedStatus = in_array($status, StaffDocumentStatus::values(), true)
            ? $status
            : StaffDocumentStatus::ACTIVE->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);

        $updated = $this->staffDocumentRepository->update($staffDocumentId, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedStatus === StaffDocumentStatus::ARCHIVED->value
                ? $normalizedReason
                : null,
        ]);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $metadata = [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'reason_required' => $normalizedStatus === StaffDocumentStatus::ARCHIVED->value,
                'reason_provided' => $normalizedReason !== null,
            ];

            $this->auditLogRepository->write(
                staffDocumentId: $staffDocumentId,
                action: 'staff-document.status.updated',
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
        $trackedFields = ['status', 'status_reason'];

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
