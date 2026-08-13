<?php

namespace App\Modules\Reception\Application\Listeners;

use App\Modules\Reception\Domain\Events\AppointmentCheckedIn;
use App\Modules\Reception\Domain\ValueObjects\ArrivalMode;
use Illuminate\Support\Facades\Log;

/**
 * Mode B (shadow) of reports/patient-arrival-checkin-modernization-plan.md
 * §3.3: logs what Mode C would create — a skeleton EmergencyTriageCase — for
 * an emergency-mode check-in, without creating anything. This is deliberately
 * a pure observer: it never writes to the database, dispatches no further
 * events, and its failure must never affect the check-in it's reacting to
 * (hence the try/catch — a logging failure here is a logging problem, not a
 * reception problem).
 *
 * The purpose is to verify this trigger condition (arrival_mode = emergency)
 * against real arrival volume before Mode C ever writes a real record. There
 * is intentionally no default-on timing decided for Mode C yet (plan §5) —
 * this listener produces the evidence that decision will be made from.
 */
class LogShadowEmergencyTriageCaseCreation
{
    public function handle(AppointmentCheckedIn $event): void
    {
        if ($event->arrivalMode !== ArrivalMode::EMERGENCY->value) {
            return;
        }

        try {
            Log::channel('reception_shadow_automation')->info(
                'Mode B shadow: would create a skeleton EmergencyTriageCase for this arrival',
                [
                    'mode' => 'B',
                    'proposed_action' => 'create_skeleton_emergency_triage_case',
                    'appointment_id' => $event->appointmentId,
                    'patient_id' => $event->patientId,
                    'arrival_mode' => $event->arrivalMode,
                    'actor_id' => $event->actorId,
                ],
            );
        } catch (\Throwable) {
            // Deliberately swallowed: shadow logging must never surface as a
            // failure of the check-in it observed.
        }
    }
}
