<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Application\Exceptions\TriageClaimConflictException;
use App\Modules\Appointment\Domain\Repositories\AppointmentAuditLogRepositoryInterface;
use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 of reports/queue-based-workflow-modernization-plan.md: makes "In
 * Triage" a real, visible state without adding a new AppointmentStatus enum
 * value — a claim is metadata alongside WAITING_TRIAGE, the same way
 * consultation_owner_user_id sits alongside in_consultation. Deliberately
 * simpler than AppointmentController::startConsultation()'s full takeover
 * flow (no previous-owner notification, no blocked-attempt audit trail):
 * this phase's scope is making the state visible, not replicating every
 * nuance consultation ownership has grown over time.
 */
class ClaimAppointmentTriageUseCase
{
    public function __construct(
        private readonly AppointmentRepositoryInterface $appointmentRepository,
        private readonly AppointmentAuditLogRepositoryInterface $auditLogRepository,
        private readonly TenantIsolationWriteGuardInterface $tenantIsolationWriteGuard,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $id, ?int $actorId, bool $forceTakeover = false): ?array
    {
        $this->tenantIsolationWriteGuard->assertTenantScopeForWrite();

        $existing = $this->appointmentRepository->findById($id);
        if (! $existing) {
            return null;
        }

        $currentStatus = (string) ($existing['status'] ?? '');
        if ($currentStatus !== AppointmentStatus::WAITING_TRIAGE->value) {
            throw ValidationException::withMessages([
                'status' => ['Only visits waiting for triage can be claimed for triage.'],
            ]);
        }

        $currentOwnerUserId = $this->normalizeOwnerUserId($existing['triage_owner_user_id'] ?? null);

        if ($currentOwnerUserId !== null && $currentOwnerUserId !== $actorId && ! $forceTakeover) {
            throw new TriageClaimConflictException($currentOwnerUserId);
        }

        $updated = $this->appointmentRepository->update($id, [
            'triage_owner_user_id' => $actorId,
            'triage_owner_assigned_at' => now(),
        ]);

        if (! $updated) {
            return null;
        }

        $this->auditLogRepository->write(
            appointmentId: $id,
            action: 'appointment.triage.claimed',
            actorId: $actorId,
            changes: [
                'triage_owner_user_id' => [
                    'before' => $existing['triage_owner_user_id'] ?? null,
                    'after' => $updated['triage_owner_user_id'] ?? null,
                ],
            ],
            metadata: [
                'takeover' => $currentOwnerUserId !== null && $currentOwnerUserId !== $actorId,
                'previous_owner_user_id' => $currentOwnerUserId,
            ],
        );

        return $updated;
    }

    private function normalizeOwnerUserId(mixed $value): ?int
    {
        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }
}
