<?php

namespace App\Support\CanonicalEncounterState;

/**
 * "O" dimension from the canonical mapping layer
 * (reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §3.3).
 *
 * EXCEPTION is deliberately kept distinct from RESULTED — it is a more
 * specific signal than the plain PENDING that GetEncounterCloseReadinessUseCase
 * now also surfaces for a pharmacy `reconciliation_exception` order, since
 * that use case no longer folds it into its terminal/non-pending bucket (see
 * clinical-note-audit/15-critical-system-integrity-review.md, finding C-11,
 * fixed).
 * UNKNOWN is a fail-closed read-failure outcome, not a clinical value.
 */
enum CanonicalOrdersDimension: string
{
    case NONE = 'NONE';
    case PENDING = 'PENDING';
    case RESULTED = 'RESULTED';
    case EXCEPTION = 'EXCEPTION';
    case UNKNOWN = 'UNKNOWN';
}
