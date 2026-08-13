<?php

namespace App\Support\CanonicalEncounterState;

/**
 * "D" dimension from the canonical mapping layer
 * (reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §3.5).
 * UNKNOWN is a fail-closed read-failure outcome, not a clinical value.
 */
enum CanonicalDiagnosisDimension: string
{
    case YES = 'YES';
    case NO = 'NO';
    case UNKNOWN = 'UNKNOWN';
}
