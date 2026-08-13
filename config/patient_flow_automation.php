<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Mode B — order-completion notifications for the ordering clinician
    |--------------------------------------------------------------------------
    |
    | Phase 4 of reports/queue-based-workflow-modernization-plan.md.
    | Mode A (shadow, always active) only logs what this would surface — see
    | App\Modules\PatientFlow\Application\Listeners\
    | LogOrderCompletionForOrderingClinician. Mode B actually returns real
    | data from GET /patient-flow/notifications: completed lab/pharmacy/
    | radiology orders, for the clinician who ordered them, whose visit is
    | still active. When disabled, that endpoint returns an empty list — the
    | route always exists (matching this codebase's current convention of not
    | flag-gating new pages, see reports/patient-arrival-checkin-audit.md §9's
    | encounters/List.vue precedent), only the data behind it is gated.
    |
    | Deliberately disabled by default: the same staged-trust reasoning as
    | reception_automation.php's Mode C flag — piloting with one department
    | before wider rollout is a workflow decision, not an engineering default.
    | The board view itself (GET /patient-flow/board) is NOT gated by this
    | flag — it is read-only visibility into data that is already visible
    | elsewhere (appointment status, order status), not a new automated
    | behavior, so it carries none of the risk this flag exists to manage.
    |
    */
    'mode_b_notifications' => [
        'enabled' => (bool) env('PATIENT_FLOW_MODE_B_NOTIFICATIONS_ENABLED', false),
    ],

];
