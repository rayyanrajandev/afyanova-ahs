<?php

namespace App\Modules\Encounter\Domain\ValueObjects;

enum EncounterStatus: string
{
    case OPENED = 'opened';
    case IN_PROGRESS = 'in_progress';
    case READY_FOR_SIGN = 'ready_for_sign';
    case SIGNED = 'signed';
    case CLOSED = 'closed';
    case AMENDED = 'amended';
    case CANCELLED = 'cancelled';

    /**
     * The statuses that mean the visit is still live — the patient has not been
     * discharged from it and is still somebody's responsibility.
     *
     * This exists because nursing asked the question with a bare string literal
     * (`where('encounters.status', 'opened')`) and lost patients as a result: a
     * clinician saving a *draft* note promotes the encounter to `in_progress`
     * (EncounterLifecycleService), so a patient who had been on the nursing
     * worklist for 33 minutes silently vanished from it. No error, no log, no
     * failing test — the nurse would have had to notice the absence of someone
     * they were never told to expect.
     *
     * Stated once, here, rather than as an array literal per screen. The
     * clinician queue had the right set all along (ClinicianQueueStage) and
     * nursing had a single-status match, which is precisely the drift a named
     * domain concept prevents.
     *
     * Note this is a *documentation* lifecycle being used to answer a
     * work-queue question, which is the deeper defect. Widening the set stops
     * patients disappearing today; it does not make this the right question to
     * ask. See reports/workspace-maturity/03-nursing.md, goals G1 and G3.
     *
     * @return array<int, string>
     */
    public static function liveStatuses(): array
    {
        return [
            self::OPENED->value,
            self::IN_PROGRESS->value,
            self::READY_FOR_SIGN->value,
        ];
    }

    /**
     * Whether this status means a clinician has finalised documentation on the
     * encounter.
     *
     * Cancelling such an encounter discards completed clinical work, so the
     * paths that close an encounter out from under a patient — handing them
     * back to reception, for instance — must check this first. A draft note is
     * not finalised; a note submitted for signature is.
     */
    public function carriesFinalisedDocumentation(): bool
    {
        return match ($this) {
            self::READY_FOR_SIGN, self::SIGNED, self::AMENDED => true,
            self::OPENED, self::IN_PROGRESS, self::CLOSED, self::CANCELLED => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $status): string => $status->value,
            self::cases(),
        );
    }
}
