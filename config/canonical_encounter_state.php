<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical Encounter State — Shadow Mode toggle
    |--------------------------------------------------------------------------
    |
    | See reports/encounter-state-machine-design/01-integration-and-migration-architecture.md,
    | §3 (Mode A vs Mode B). Defaults to false: with no .env change, the system
    | behaves exactly as Mode A (Legacy Only) — the resolver is never invoked.
    |
    */

    'shadow_mode_enabled' => (bool) env('CANONICAL_ENCOUNTER_SHADOW_MODE_ENABLED', false),

];
