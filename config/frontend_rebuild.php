<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encounter Workspace V2 — rebuild toggle
    |--------------------------------------------------------------------------
    |
    | See reports/clinical-notes-frontend-rebuild-plan.md. Defaults to false:
    | with no .env change, the /encounters/{id}/v2 route 404s and the existing
    | encounters/{id} Workspace page is completely unaffected.
    |
    */

    'workspace_v2_enabled' => (bool) env('FRONTEND_WORKSPACE_V2_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Encounters list — new page toggle
    |--------------------------------------------------------------------------
    |
    | There is no existing /encounters list page to protect — this is
    | genuinely new (see the "no dormant encounters list" finding in the
    | encounters-list analysis). Still flag-gated for a controlled rollout,
    | same convention as workspace_v2_enabled. Defaults to false: with no
    | .env change, the /encounters route 404s.
    |
    */

    'encounters_list_enabled' => (bool) env('FRONTEND_ENCOUNTERS_LIST_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Patient Chart V2 — rebuild toggle
    |--------------------------------------------------------------------------
    |
    | See reports/patient-chart-rebuild-plan.md. Frontend-only rebuild of the
    | patients/{id}/chart aggregation page (TanStack Query composables instead
    | of ~10 hand-rolled fetch/ref sets). Defaults to false: with no .env
    | change, the /patients/{id}/chart/v2 route 404s and the existing
    | patients/{id}/chart page is completely unaffected.
    |
    */

    'patient_chart_v2_enabled' => (bool) env('FRONTEND_PATIENT_CHART_V2_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Medical Records Index V2 — rebuild toggle
    |--------------------------------------------------------------------------
    |
    | See reports/medical-records-index-rebuild-plan.md. Frontend-only rebuild
    | of the medical-records registry/status-governance page (TanStack Query
    | composables instead of ~150 hand-rolled top-level functions). Defaults to
    | false: with no .env change, the /medical-records/v2 route 404s and the
    | existing medical-records page is completely unaffected.
    |
    */

    'medical_records_index_v2_enabled' => (bool) env('FRONTEND_MEDICAL_RECORDS_INDEX_V2_ENABLED', false),

];
