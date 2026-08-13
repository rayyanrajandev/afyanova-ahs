<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistStatusNotEligibleException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class UpdateInpatientWardDischargeChecklistUseCase
{
    public function __construct(
        private readonly InpatientWardDischargeChecklistRepositoryInterface $checklistRepository,
        private readonly InpatientWardDischargeChecklistAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(string $id, array $payload, ?int $actorId = null): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->checklistRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $updatePayload = $payload;
        $updatePayload['is_ready_for_discharge'] = $this->isReadyForDischarge($payload, $existing);
        $updatePayload['last_reviewed_by_user_id'] = $actorId;
        $updatePayload['reviewed_at'] = now();

        $currentStatus = (string) ($existing['status'] ?? '');
        if ($this->statusRequiresReadiness($currentStatus) && ! $updatePayload['is_ready_for_discharge']) {
            throw new InpatientWardDischargeChecklistStatusNotEligibleException(
                'Checklist items cannot be uncompleted while discharge checklist status is ready/completed. Move status to blocked or draft first.',
            );
        }

        $updated = $this->checklistRepository->update($id, $updatePayload);
        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            inpatientWardDischargeChecklistId: $id,
            action: 'inpatient-ward-discharge-checklist.updated',
            actorId: $actorId,
            changes: [
                'before' => $existing,
                'after' => $updated,
            ],
        );

        return $updated;
    }

    private function isReadyForDischarge(array $payload, array $existing): bool
    {
        $clinicalSummaryCompleted = array_key_exists('clinical_summary_completed', $payload)
            ? (bool) $payload['clinical_summary_completed']
            : (bool) ($existing['clinical_summary_completed'] ?? false);

        $medicationReconciliationCompleted = array_key_exists('medication_reconciliation_completed', $payload)
            ? (bool) $payload['medication_reconciliation_completed']
            : (bool) ($existing['medication_reconciliation_completed'] ?? false);

        $followUpPlanCompleted = array_key_exists('follow_up_plan_completed', $payload)
            ? (bool) $payload['follow_up_plan_completed']
            : (bool) ($existing['follow_up_plan_completed'] ?? false);

        $patientEducationCompleted = array_key_exists('patient_education_completed', $payload)
            ? (bool) $payload['patient_education_completed']
            : (bool) ($existing['patient_education_completed'] ?? false);

        $transportArranged = array_key_exists('transport_arranged', $payload)
            ? (bool) $payload['transport_arranged']
            : (bool) ($existing['transport_arranged'] ?? false);

        $billingCleared = array_key_exists('billing_cleared', $payload)
            ? (bool) $payload['billing_cleared']
            : (bool) ($existing['billing_cleared'] ?? false);

        $documentationCompleted = array_key_exists('documentation_completed', $payload)
            ? (bool) $payload['documentation_completed']
            : (bool) ($existing['documentation_completed'] ?? false);

        return $clinicalSummaryCompleted
            && $medicationReconciliationCompleted
            && $followUpPlanCompleted
            && $patientEducationCompleted
            && $transportArranged
            && $billingCleared
            && $documentationCompleted;
    }

    private function statusRequiresReadiness(string $status): bool
    {
        return $status === InpatientWardDischargeChecklistStatus::READY->value
            || $status === InpatientWardDischargeChecklistStatus::COMPLETED->value;
    }
}
