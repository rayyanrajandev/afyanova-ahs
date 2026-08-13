<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode C — skeleton EmergencyTriageCase creation
    |--------------------------------------------------------------------------
    |
    | Phase 5 of reports/patient-arrival-checkin-modernization-plan.md.
    | Mode A (manual, default) requires a human to create every
    | EmergencyTriageCase. Mode B (shadow) only logs what Mode C would do —
    | see App\Modules\Reception\Application\Listeners\
    | LogShadowEmergencyTriageCaseCreation, always active regardless of this
    | flag. Mode C actually creates a skeleton (no clinical fields) case on
    | emergency-mode check-in.
    |
    | Was deliberately disabled by default while this was purely an
    | engineering capability with no clinical sign-off — see the plan's §5
    | risk register. Enabled by explicit product decision alongside
    | reports/emergency-queue-modernization-plan.md: emergency/Queue.vue is
    | now a real, used page, and without this flag an emergency-mode
    | check-in from Reception or the patients list produced an Appointment
    | with no corresponding EmergencyTriageCase — invisible to any
    | clinician working that queue. See that plan's "sync gap" update for
    | the full trace of what was disconnected before this changed.
    |
    */
    'mode_c_skeleton_emergency_triage_case' => [
        'enabled' => (bool) env('RECEPTION_MODE_C_SKELETON_TRIAGE_CASE_ENABLED', true),
    ],

];
