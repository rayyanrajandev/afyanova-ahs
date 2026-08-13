<?php

namespace App\Modules\Reception\Application\Listeners;

use App\Modules\Appointment\Domain\Events\AppointmentStatusChanged;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use App\Modules\EmergencyTriage\Application\UseCases\UpdateEmergencyTriageCaseStatusUseCase;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseStatus;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mode C's missing counterpart (2026-08-12, direct user bug report):
 * CreateSkeletonEmergencyTriageCase auto-creates a WAITING EmergencyTriageCase
 * on emergency check-in, but nothing ever resolved it — cancelling or
 * completing the linked Appointment (CancelQueueItemUseCase,
 * UpdateAppointmentStatusUseCase) left the skeleton case sitting at WAITING
 * forever. Since findActiveForPatient() (the check
 * CreateAppointmentUseCase::assertNoActivePatientEncounterConflict() uses)
 * treats WAITING as active, this permanently blocked the patient from ever
 * being checked in or scheduled again — reported live: cancelling Salome
 * Mgonja's visit still showed "Latest visit: Cancelled" on her profile, but
 * re-check-in failed with "Patient has an active emergency case... Resolve
 * or discharge the emergency visit before scheduling a new appointment."
 *
 * Listens on AppointmentStatusChanged (not a Reception-specific event) since
 * that's the one event every status-changing call site already funnels
 * through (UpdateAppointmentStatusUseCase's own docblock) — CancelQueueItem,
 * a future "complete visit" action, etc. all covered without listening in
 * N places.
 *
 * Deliberately only touches a case that is STILL an untouched Mode C
 * skeleton (`triage_level === 'unassigned'`, the exact marker
 * CreateSkeletonEmergencyTriageCase sets and documents as reserved for this
 * purpose) — the moment a clinician does any real triage work
 * (triage_level moves off 'unassigned'), this listener backs off and leaves
 * resolution to a human. Auto-cancelling a real, in-progress clinical
 * assessment just because an unrelated appointment record was closed would
 * be a patient-safety problem, not a convenience.
 */
class ResolveSkeletonEmergencyTriageCaseOnAppointmentClosure
{
    private const ACTIVE_CASE_STATUSES = [
        EmergencyTriageCaseStatus::WAITING->value,
        EmergencyTriageCaseStatus::TRIAGED->value,
        EmergencyTriageCaseStatus::IN_TREATMENT->value,
    ];

    public function __construct(
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
        private readonly UpdateEmergencyTriageCaseStatusUseCase $updateEmergencyTriageCaseStatusUseCase,
    ) {}

    public function handle(AppointmentStatusChanged $event): void
    {
        if (! (bool) config('reception_automation.mode_c_skeleton_emergency_triage_case.enabled', false)) {
            return;
        }

        $resolvedCaseStatus = match ($event->newStatus) {
            AppointmentStatus::CANCELLED->value => EmergencyTriageCaseStatus::CANCELLED->value,
            AppointmentStatus::COMPLETED->value => EmergencyTriageCaseStatus::DISCHARGED->value,
            // NO_SHOW is unreachable with a linked skeleton case in
            // practice (the skeleton is only ever created on check-in, by
            // which point the appointment can no longer become a no-show),
            // and every other status is non-terminal — nothing to resolve.
            default => null,
        };

        if ($resolvedCaseStatus === null) {
            return;
        }

        try {
            $case = $this->emergencyTriageCaseRepository->findByAppointmentId($event->appointmentId);
            if ($case === null) {
                return;
            }

            if (! in_array($case['status'] ?? null, self::ACTIVE_CASE_STATUSES, true)) {
                return;
            }

            if (($case['triage_level'] ?? null) !== 'unassigned') {
                return;
            }

            $this->updateEmergencyTriageCaseStatusUseCase->execute(
                id: (string) $case['id'],
                status: $resolvedCaseStatus,
                reason: sprintf(
                    'Auto-resolved: linked appointment was %s.',
                    $event->newStatus,
                ),
                dispositionNotes: null,
                actorId: $event->actorId,
            );

            Log::channel('reception_shadow_automation')->info(
                'Mode C: auto-resolved a skeleton EmergencyTriageCase after its linked appointment closed',
                [
                    'mode' => 'C',
                    'action_taken' => 'resolved_skeleton_emergency_triage_case',
                    'appointment_id' => $event->appointmentId,
                    'patient_id' => $event->patientId,
                    'emergency_triage_case_id' => $case['id'] ?? null,
                    'appointment_new_status' => $event->newStatus,
                    'emergency_triage_case_new_status' => $resolvedCaseStatus,
                    'actor_id' => $event->actorId,
                ],
            );
        } catch (Throwable $exception) {
            Log::channel('reception_shadow_automation')->warning(
                'Mode C: failed to auto-resolve skeleton EmergencyTriageCase',
                [
                    'mode' => 'C',
                    'action_taken' => 'resolve_skeleton_emergency_triage_case_failed',
                    'appointment_id' => $event->appointmentId,
                    'error' => $exception->getMessage(),
                ],
            );
        }
    }
}
