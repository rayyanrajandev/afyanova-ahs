<?php

namespace App\Modules\Reception\Application\Listeners;

use App\Modules\EmergencyTriage\Application\UseCases\CreateEmergencyTriageCaseUseCase;
use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mode C (opt-in, disabled by default — see config/reception_automation.php)
 * of reports/patient-arrival-checkin-modernization-plan.md §3.3: creates a
 * skeleton EmergencyTriageCase — WAITING status, no real clinical assessment
 * — for an emergency-mode check-in, so a patient is never administratively
 * "arrived" but clinically invisible (plan §2.1). This is advisory, not
 * authoritative: the UI (when Phase 6 builds it) must treat this as a
 * pre-filled suggestion a clinician still opens and confirms, never a
 * silent, unreviewable background action — hence status_reason marks it as
 * auto-created and unconfirmed, vitals_summary is never set, and
 * triage_level/chief_complaint get clearly-marked placeholders rather than
 * real clinical content (see the inline comment at the create() call — both
 * are NOT NULL at the schema level, so a true zero-clinical-fields skeleton
 * isn't representable).
 *
 * Reuses CreateEmergencyTriageCaseUseCase — the same validated write path a
 * clinician's own request goes through — rather than inserting directly, so
 * this listener can never produce a record with weaker validation than a
 * human-created one.
 *
 * A failure here must never surface as a failure of the check-in it
 * observed: this is best-effort automation layered on top of a completed,
 * already-successful check-in, not a required step of it.
 */
class CreateSkeletonEmergencyTriageCase
{
    public function __construct(
        private readonly CreateEmergencyTriageCaseUseCase $createEmergencyTriageCaseUseCase,
        private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository,
    ) {}

    public function handle(AppointmentCheckedIn $event): void
    {
        if (! (bool) config('reception_automation.mode_c_skeleton_emergency_triage_case.enabled', false)) {
            return;
        }

        if ($event->arrivalMode !== ArrivalMode::EMERGENCY->value) {
            return;
        }

        try {
            // Idempotency: AppointmentCheckedIn can legitimately fire more than
            // once for the same appointment (e.g. a same-status re-check-in —
            // AppointmentStatus::canTransitionTo() always allows a no-op
            // same-status call). Never create a second skeleton case for a
            // visit that already has one.
            if ($this->emergencyTriageCaseRepository->existsByAppointmentId($event->appointmentId)) {
                return;
            }

            $case = $this->createEmergencyTriageCaseUseCase->execute([
                'patient_id' => $event->patientId,
                'appointment_id' => $event->appointmentId,
                'arrived_at' => now(),
                'status_reason' => 'Auto-created from reception check-in (Mode C) — pending clinician confirmation.',
                // emergency_triage_cases.triage_level and .chief_complaint are
                // both NOT NULL at the schema level (a real clinical case
                // always has both captured at intake) — a true
                // "no-clinical-fields-at-all" skeleton isn't representable.
                // These are placeholders, not real clinical content:
                // 'unassigned' is deliberately outside
                // ListEmergencyTriageCasesUseCase's red/yellow/green filter
                // whitelist, so it can never be mistaken for a real assessed
                // level or match a color-filtered queue view — only the
                // unfiltered list/status-counts surface it, exactly where a
                // clinician would see it and assign the real level and
                // complaint.
                'triage_level' => 'unassigned',
                'chief_complaint' => 'Not yet assessed — auto-created at check-in, pending clinician triage.',
            ], $event->actorId);

            Log::channel('reception_shadow_automation')->info(
                'Mode C: created a skeleton EmergencyTriageCase for this arrival',
                [
                    'mode' => 'C',
                    'action_taken' => 'created_skeleton_emergency_triage_case',
                    'appointment_id' => $event->appointmentId,
                    'patient_id' => $event->patientId,
                    'emergency_triage_case_id' => $case['id'] ?? null,
                    'actor_id' => $event->actorId,
                ],
            );
        } catch (Throwable $exception) {
            try {
                Log::channel('reception_shadow_automation')->warning(
                    'Mode C: failed to create skeleton EmergencyTriageCase',
                    [
                        'mode' => 'C',
                        'appointment_id' => $event->appointmentId,
                        'patient_id' => $event->patientId,
                        'error' => $exception->getMessage(),
                    ],
                );
            } catch (Throwable) {
                // Logging failure is not this listener's problem to surface either.
            }
        }
    }
}
