<?php

namespace App\Modules\InpatientWard\Application\UseCases;

use App\Modules\InpatientWard\Application\Exceptions\InpatientWardAdmissionNotFoundException;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistAlreadyExistsException;
use App\Modules\InpatientWard\Application\Exceptions\InpatientWardDischargeChecklistStatusNotEligibleException;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardCensusRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistAuditLogRepositoryInterface;
use App\Modules\InpatientWard\Domain\Repositories\InpatientWardDischargeChecklistRepositoryInterface;
use App\Modules\InpatientWard\Domain\ValueObjects\InpatientWardDischargeChecklistStatus;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class CreateInpatientWardDischargeChecklistUseCase
{
    public function __construct(
        private readonly InpatientWardCensusRepositoryInterface $censusRepository,
        private readonly InpatientWardDischargeChecklistRepositoryInterface $checklistRepository,
        private readonly InpatientWardDischargeChecklistAuditLogRepositoryInterface $auditLogRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    public function execute(array $payload, ?int $actorId = null): array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $admissionId = (string) $payload['admission_id'];
        $admission = $this->censusRepository->findCurrentAdmissionById($admissionId);
        if (! $admission) {
            throw new InpatientWardAdmissionNotFoundException(
                'Inpatient admission not found in current ward census.',
            );
        }

        $existing = $this->checklistRepository->findByAdmissionId($admission['id']);
        if ($existing) {
            throw new InpatientWardDischargeChecklistAlreadyExistsException(
                'A discharge checklist already exists for this admission.',
            );
        }

        $requestedStatus = (string) ($payload['status'] ?? InpatientWardDischargeChecklistStatus::DRAFT->value);
        $isReadyForDischarge = $this->isReadyForDischarge($payload);

        if ($this->statusRequiresReadiness($requestedStatus) && ! $isReadyForDischarge) {
            throw new InpatientWardDischargeChecklistStatusNotEligibleException(
                'Discharge checklist cannot move to ready/completed until all required checklist items are complete.',
            );
        }

        $createPayload = [
            'tenant_id' => $this->platformScopeContext->tenantId(),
            'facility_id' => $this->platformScopeContext->facilityId(),
            'admission_id' => $admission['id'],
            'patient_id' => $admission['patient_id'],
            'status' => $requestedStatus,
            'status_reason' => $payload['status_reason'] ?? null,
            'clinical_summary_completed' => (bool) ($payload['clinical_summary_completed'] ?? false),
            'medication_reconciliation_completed' => (bool) ($payload['medication_reconciliation_completed'] ?? false),
            'follow_up_plan_completed' => (bool) ($payload['follow_up_plan_completed'] ?? false),
            'patient_education_completed' => (bool) ($payload['patient_education_completed'] ?? false),
            'transport_arranged' => (bool) ($payload['transport_arranged'] ?? false),
            'billing_cleared' => (bool) ($payload['billing_cleared'] ?? false),
            'documentation_completed' => (bool) ($payload['documentation_completed'] ?? false),
            'is_ready_for_discharge' => $isReadyForDischarge,
            'last_reviewed_by_user_id' => $actorId,
            'reviewed_at' => now(),
            'notes' => $payload['notes'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ];

        $created = $this->checklistRepository->create($createPayload);

        $this->auditLogRepository->write(
            inpatientWardDischargeChecklistId: $created['id'],
            action: 'inpatient-ward-discharge-checklist.created',
            actorId: $actorId,
            changes: [
                'after' => $created,
            ],
        );

        return $created;
    }

    private function isReadyForDischarge(array $payload): bool
    {
        return (bool) ($payload['clinical_summary_completed'] ?? false)
            && (bool) ($payload['medication_reconciliation_completed'] ?? false)
            && (bool) ($payload['follow_up_plan_completed'] ?? false)
            && (bool) ($payload['patient_education_completed'] ?? false)
            && (bool) ($payload['transport_arranged'] ?? false)
            && (bool) ($payload['billing_cleared'] ?? false)
            && (bool) ($payload['documentation_completed'] ?? false);
    }

    private function statusRequiresReadiness(string $status): bool
    {
        return $status === InpatientWardDischargeChecklistStatus::READY->value
            || $status === InpatientWardDischargeChecklistStatus::COMPLETED->value;
    }
}
