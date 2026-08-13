<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\ClinicalSpecialtyStatus;

class UpdateClinicalSpecialtyStatusUseCase
{
    public function __construct(
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
        private readonly ClinicalSpecialtyAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(
        string $id,
        string $status,
        ?string $reason,
        ?int $actorId = null,
    ): ?array {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->clinicalSpecialtyRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($status, ClinicalSpecialtyStatus::values(), true)
            ? $status
            : ClinicalSpecialtyStatus::ACTIVE->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);

        $updated = $this->clinicalSpecialtyRepository->update($id, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedStatus === ClinicalSpecialtyStatus::INACTIVE->value
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
                'reason_required' => $normalizedStatus === ClinicalSpecialtyStatus::INACTIVE->value,
                'reason_provided' => $normalizedReason !== null,
            ];

            $this->auditLogRepository->write(
                specialtyId: $id,
                tenantId: $this->platformScopeContext->tenantId(),
                staffProfileId: null,
                action: 'specialty.status.updated',
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
