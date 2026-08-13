<?php

namespace App\Support\CanonicalEncounterState;

/**
 * "B" dimension from the canonical mapping layer
 * (reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §3.4).
 * UNKNOWN is a fail-closed read-failure outcome, not a clinical value.
 */
enum CanonicalBillingDimension: string
{
    case READY = 'READY';
    case NOT_READY = 'NOT_READY';
    case UNKNOWN = 'UNKNOWN';
}
