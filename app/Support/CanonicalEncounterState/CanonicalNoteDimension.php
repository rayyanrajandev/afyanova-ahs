<?php

namespace App\Support\CanonicalEncounterState;

/**
 * "N" dimension from the canonical mapping layer
 * (reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §3.2).
 *
 * Derived from every non-archived consultation-type MedicalRecord row tied to
 * the encounter: SIGNED only if all of them are finalized/amended, DRAFT if any
 * is still draft, NONE if there are none. UNKNOWN is a fail-closed read-failure
 * outcome, not a clinical value.
 */
enum CanonicalNoteDimension: string
{
    case NONE = 'NONE';
    case DRAFT = 'DRAFT';
    case SIGNED = 'SIGNED';
    case UNKNOWN = 'UNKNOWN';
}
