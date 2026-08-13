<?php

namespace App\Modules\Appointment\Application\Exceptions;

use RuntimeException;

/**
 * Phase 2 of reports/queue-based-workflow-modernization-plan.md — mirrors
 * the consultation-owner-conflict shape (AppointmentController's
 * consultationOwnerConflictResponse()) for the new triage claim/lock, so a
 * second nurse's claim attempt is rejected the same way a second
 * clinician's consultation takeover attempt already is, not silently
 * overwritten.
 */
class TriageClaimConflictException extends RuntimeException
{
    public function __construct(public readonly int $ownerUserId)
    {
        parent::__construct('This visit is already claimed by another nurse for triage.');
    }
}
