<?php

namespace App\Support\CanonicalEncounterState;

/**
 * The 8 canonical case-status states from the Canonical Encounter State Machine
 * design (reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §1.2),
 * unchanged here.
 *
 * INDETERMINATE is not one of the 8 clinical states. It is a computation-outcome
 * value only: the resolver reports it when a required read failed and the
 * fail-closed rule (design doc 01 §2.4) applies. It must never be interpreted
 * as a clinical state.
 */
enum CanonicalEncounterState: string
{
    case REGISTERED = 'REGISTERED';
    case IN_CONSULTATION = 'IN_CONSULTATION';
    case WORKUP_IN_PROGRESS = 'WORKUP_IN_PROGRESS';
    case AWAITING_RESULTS = 'AWAITING_RESULTS';
    case READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    case READY_FOR_DISCHARGE = 'READY_FOR_DISCHARGE';
    case CLOSED = 'CLOSED';
    case CANCELLED = 'CANCELLED';
    case INDETERMINATE = 'INDETERMINATE';
}
