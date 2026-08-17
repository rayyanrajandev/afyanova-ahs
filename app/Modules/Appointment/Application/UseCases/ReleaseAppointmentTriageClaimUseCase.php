<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\TriageClaimConflictException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\PatientFlow\Application\Services\RecordPatientFlowTransitionService;
use App\Modules\PatientFlow\Domain\ValueObjects\PatientFlowStep;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

/**
 * Phase 2 of reports/queue-based-workflow-modernization-plan.md: lets a nurse
 * give up a triage claim (pulled away, handed off) so another nurse can pick
 * it up without needing forceTakeover. Releasing an unclaimed visit is a
 * no-op, not an error — the caller's intent ("this shouldn't be mine
 * anymore") is already satisfied.
 */
class ReleaseAppointmentTriageClaimUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
        private readonly RecordPatientFlowTransitionService $recordPatientFlowTransition,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, ?int $actorId): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $currentOwnerUserId = $this->normalizeOwnerUserId($existing['triage_owner_user_id'] ?? null);

        if ($currentOwnerUserId === null) {
            return $existing;
        }

        if ($currentOwnerUserId !== $actorId) {
            throw new TriageClaimConflictException($currentOwnerUserId);
        }

        $updated = $this->appointmentRepository->update($id, [
            'triage_owner_user_id' => null,
            'triage_owner_assigned_at' => null,
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            appointmentId: $id,
            action: 'appointment.triage.released',
            actorId: $actorId,
            changes: [
                'triage_owner_user_id' => [
                    'before' => $existing['triage_owner_user_id'] ?? null,
                    'after' => null,
                ],
            ],
            metadata: [],
        );

        // Flow log (2026-08-16 activity audit). Releasing a claim puts the
        // patient back in the triage queue; without this the timeline showed a
        // pickup with no matching hand-back, which reads as though the nurse is
        // still with them.
        $this->recordPatientFlowTransition->record(
            toStep: PatientFlowStep::WAITING_TRIAGE,
            patientId: (string) $updated['patient_id'],
            appointmentId: (string) $updated['id'],
            actorId: $actorId,
            source: 'triage.claim_released',
            facilityId: $updated['facility_id'] ?? null,
        );

        return $updated;
    }

    private function normalizeOwnerUserId(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
