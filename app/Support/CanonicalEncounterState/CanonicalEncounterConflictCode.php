<?php

namespace App\Support\CanonicalEncounterState;

/**
 * CONFLICT-01 .. CONFLICT-10 as defined in
 * reports/encounter-state-machine-design/00-canonical-encounter-state-machine.md §4.
 * Codes, conditions, and severities are reproduced here verbatim from that
 * design document — this enum does not redefine or reinterpret them.
 */
enum CanonicalEncounterConflictCode: string
{
    case CONFLICT_01 = 'CONFLICT-01';
    case CONFLICT_02 = 'CONFLICT-02';
    case CONFLICT_03 = 'CONFLICT-03';
    case CONFLICT_04 = 'CONFLICT-04';
    case CONFLICT_05 = 'CONFLICT-05';
    case CONFLICT_06 = 'CONFLICT-06';
    case CONFLICT_07 = 'CONFLICT-07';
    case CONFLICT_08 = 'CONFLICT-08';
    case CONFLICT_09 = 'CONFLICT-09';
    case CONFLICT_10 = 'CONFLICT-10';

    public function severity(): string
    {
        return match ($this) {
            self::CONFLICT_01, self::CONFLICT_03, self::CONFLICT_04 => 'critical',
            self::CONFLICT_02, self::CONFLICT_05, self::CONFLICT_06, self::CONFLICT_07 => 'high',
            self::CONFLICT_08 => 'medium_high',
            self::CONFLICT_09 => 'medium',
            self::CONFLICT_10 => 'low_medium',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CONFLICT_01 => 'Encounter closed with pending clinical orders',
            self::CONFLICT_02 => 'Note signed but encounter status not advanced',
            self::CONFLICT_03 => 'Multiple unresolved notes for this encounter',
            self::CONFLICT_04 => 'Stale signature on a non-final note',
            self::CONFLICT_05 => 'Duplicate encounter for the same visit',
            self::CONFLICT_06 => 'Encounter closed with unbilled services',
            self::CONFLICT_07 => 'Divergent primary-note resolution',
            self::CONFLICT_08 => 'Medication reconciliation exception masked as resolved',
            self::CONFLICT_09 => 'Encounter status advanced only via the note-sync side channel',
            self::CONFLICT_10 => 'Encounter status is cancelled',
        };
    }
}
