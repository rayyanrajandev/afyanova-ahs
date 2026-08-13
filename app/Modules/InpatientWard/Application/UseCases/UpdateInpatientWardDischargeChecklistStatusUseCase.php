<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistStatusNotEligibleException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardDischargeChecklistStatusUseCase
{
    public function __construct(
        private readonly InpatientWardDischargeChecklistRepositoryInterface $checklistRepository,
        private readonly InpatientWardDischargeChecklistAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->checklistRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $isReadyForDischarge = (bool) ($existing['is_ready_for_discharge'] ?? false);
        if ($this->statusRequiresReadiness($status) && ! $isReadyForDischarge) {
            throw new InpatientWardDischargeChecklistStatusNotEligibleException(
                'Discharge checklist cannot move to ready/completed until all required checklist items are complete.',
            );
        }

        $updated = $this->checklistRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
            'last_reviewed_by_user_id' => $actorId,
            'reviewed_at' => now(),
        ]);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            inpatientWardDischargeChecklistId: $id,
            action: 'inpatient-ward-discharge-checklist.status.updated',
            actorId: $actorId,
            changes: [
                'status' => [
                    'before' => $existing['status'] ?? null,
                    'after' => $updated['status'] ?? null,
                ],
                'status_reason' => [
                    'before' => $existing['status_reason'] ?? null,
                    'after' => $updated['status_reason'] ?? null,
                ],
            ],
            metadata: [
                'transition' => [
                    'from' => $existing['status'] ?? null,
                    'to' => $updated['status'] ?? null,
                ],
                'readiness_required_for_status' => $this->statusRequiresReadiness($status),
                'readiness_available' => $isReadyForDischarge,
                'blocked_reason_required' => $status === InpatientWardDischargeChecklistStatus::BLOCKED->value,
                'blocked_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
                'completion_readiness_required' => $status === InpatientWardDischargeChecklistStatus::COMPLETED->value,
                'completion_readiness_satisfied' => $status === InpatientWardDischargeChecklistStatus::COMPLETED->value
                    ? $isReadyForDischarge
                    : false,
            ],
        );

        return $updated;
    }

    private function statusRequiresReadiness(string $status): bool
    {
        return $status === InpatientWardDischargeChecklistStatus::READY->value
            || $status === InpatientWardDischargeChecklistStatus::COMPLETED->value;
    }
}
