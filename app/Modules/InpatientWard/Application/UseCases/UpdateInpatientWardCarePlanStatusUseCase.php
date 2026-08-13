<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCarePlanRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardCarePlanStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardCarePlanStatusUseCase
{
    public function __construct(
        private readonly InpatientWardCarePlanRepositoryInterface $carePlanRepository,
        private readonly InpatientWardCarePlanAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, string $status, ?string $reason, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->carePlanRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updated = $this->carePlanRepository->update($id, [
            'status' => $status,
            'status_reason' => $reason,
            'last_updated_by_user_id' => $actorId,
        ]);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            inpatientWardCarePlanId: $id,
            action: 'inpatient-ward-care-plan.status.updated',
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
                'completion_evidence_required' => $status === InpatientWardCarePlanStatus::COMPLETED->value,
                'completion_evidence_provided' => $status === InpatientWardCarePlanStatus::COMPLETED->value
                    ? $this->hasCompletionEvidence($updated)
                    : false,
                'cancellation_reason_required' => $status === InpatientWardCarePlanStatus::CANCELLED->value,
                'cancellation_reason_provided' => trim((string) ($updated['status_reason'] ?? '')) !== '',
            ],
        );

        return $updated;
    }

    private function hasCompletionEvidence(array $carePlan): bool
    {
        $planText = trim((string) ($carePlan['plan_text'] ?? ''));
        $goals = is_array($carePlan['goals'] ?? null) ? $carePlan['goals'] : [];
        $interventions = is_array($carePlan['interventions'] ?? null) ? $carePlan['interventions'] : [];

        return $planText !== '' || count($goals) > 0 || count($interventions) > 0;
    }
}
