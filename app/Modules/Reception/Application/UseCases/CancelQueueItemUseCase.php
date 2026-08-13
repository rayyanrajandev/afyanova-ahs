<?php

namespace App\Modules\Reception\Application\UseCases;

use App\Modules\Appointment\Application\UseCases\UpdateAppointmentStatusUseCase;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\Encounter\Application\Services\EncounterLifecycleService;
use App\Modules\Encounter\Application\Services\EncounterResolverService;
use App\Modules\Patient\Domain\Repositories\PatientAuditLogRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Bug fix (2026-08-11): ReceptionController::cancelQueueItem() previously
 * called UpdateAppointmentStatusUseCase directly — the mirror-image gap of
 * CheckInUseCase, which exists precisely because a bare appointment-status
 * write isn't the whole story for a visit. Two side effects were missing:
 *
 *  1. The Encounter CheckInUseCase opened at check-in was never touched, so
 *     it stayed at OPENED forever — the Patient Profile's "Latest visit"
 *     card only maps status === 'closed' to "complete", so a cancelled
 *     visit displayed as permanently "In progress".
 *  2. Nothing about the cancellation reached the patient's own audit trail
 *     (PatientAuditLogRepositoryInterface, GET /patients/{id}/activity-feed)
 *     — only patient-record CRUD (registration, demographics, allergies)
 *     ever wrote there, so front-desk visit activity was invisible on the
 *     card literally named "Audit trail".
 *
 * Follows CheckInUseCase's own established shape: a Reception-owned use
 * case orchestrates the generic Appointment/Encounter/Patient-module calls
 * in one transaction, rather than teaching any of those shared, multi-
 * workspace use cases about Reception-specific side effects.
 */
class CancelQueueItemUseCase
{
    public function __construct(
        private readonly UpdateAppointmentStatusUseCase $updateAppointmentStatusUseCase,
        private readonly EncounterResolverService $encounterResolverService,
        private readonly EncounterLifecycleService $encounterLifecycleService,
        private readonly PatientAuditLogRepositoryInterface $patientAuditLogRepository,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(
        string $appointmentId,
        ?string $reason,
        ?int $actorId,
        bool $isFacilitySuperAdmin = false,
    ): ?array {
        return DB::transaction(function () use ($appointmentId, $reason, $actorId, $isFacilitySuperAdmin): ?array {
            $appointment = $this->updateAppointmentStatusUseCase->execute(
                id: $appointmentId,
                status: AppointmentStatus::CANCELLED->value,
                reason: $reason,
                actorId: $actorId,
                isFacilitySuperAdmin: $isFacilitySuperAdmin,
            );

            if ($appointment === null) {
                return null;
            }

            // Best-effort, not required for the cancel itself to succeed: a
            // visit checked in before Phase 3 shipped (or check-in failing
            // partway for some other reason) may have no Encounter at all.
            $encounter = $this->encounterResolverService->findByAppointmentId($appointmentId);
            if ($encounter !== null) {
                $this->encounterLifecycleService->cancel(
                    encounterId: (string) $encounter->id,
                    reason: $reason,
                    actorId: $actorId,
                );
            }

            $this->patientAuditLogRepository->write(
                patientId: (string) $appointment['patient_id'],
                action: 'patient.visit.cancelled',
                actorId: $actorId,
                changes: [],
                metadata: [
                    'appointmentId' => $appointmentId,
                    'reason' => $reason,
                ],
            );

            return $appointment;
        });
    }
}
