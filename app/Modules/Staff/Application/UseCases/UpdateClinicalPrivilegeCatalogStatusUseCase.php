<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogAuditLogRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\ClinicalPrivilegeCatalogStatus;

class UpdateClinicalPrivilegeCatalogStatusUseCase
{
    public function __construct(
        private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository,
        private readonly ClinicalPrivilegeCatalogAuditLogRepositoryInterface $auditLogRepository,
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

        $existing = $this->clinicalPrivilegeCatalogRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $normalizedStatus = in_array($status, ClinicalPrivilegeCatalogStatus::values(), true)
            ? $status
            : ClinicalPrivilegeCatalogStatus::ACTIVE->value;
        $normalizedReason = $this->nullableTrimmedValue($reason);

        $updated = $this->clinicalPrivilegeCatalogRepository->update($id, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedStatus === ClinicalPrivilegeCatalogStatus::INACTIVE->value
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
                'reason_required' => $normalizedStatus === ClinicalPrivilegeCatalogStatus::INACTIVE->value,
                'reason_provided' => $normalizedReason !== null,
            ];

            $this->auditLogRepository->write(
                privilegeCatalogId: $id,
                tenantId: $this->platformScopeContext->tenantId(),
                action: 'privilege-catalog.status.updated',
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
