<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityConfigurationAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityConfigurationStatus;
use DomainException;

class UpdateFacilityConfigurationStatusUseCase
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository,
        private readonly FacilityConfigurationAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->facilityConfigurationRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $normalizedStatus = strtolower(trim($status));
        if (! in_array($normalizedStatus, FacilityConfigurationStatus::values(), true)) {
            throw new DomainException('Invalid facility status.');
        }

        $normalizedReason = $this->nullableTrimmedValue($reason);
        if ($normalizedStatus === FacilityConfigurationStatus::INACTIVE->value && $normalizedReason === null) {
            throw new DomainException('Reason is required when status is inactive.');
        }

        $updated = $this->facilityConfigurationRepository->update($id, [
            'status' => $normalizedStatus,
            'status_reason' => $normalizedReason,
        ]);
        if (! $updated) {
            return null;
        }

        $changes = $this->extractChanges($existing, $updated);
        if ($changes !== []) {
            $reasonRequired = $normalizedStatus === FacilityConfigurationStatus::INACTIVE->value;
            $this->auditLogRepository->write(
                facilityId: $id,
                action: 'platform.facilities.status.updated',
                actorId: $actorId,
                changes: $changes,
                metadata: [
                    'transition' => [
                        'from' => $existing['status'] ?? null,
                        'to' => $updated['status'] ?? null,
                    ],
                    'reason_required' => $reasonRequired,
                    'reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                ],
            );
        }

        return $updated;
    }

    private function nullableTrimmedValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractChanges(array $before, array $after): array
    {
        $trackedFields = [
            'status',
            'status_reason',
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
