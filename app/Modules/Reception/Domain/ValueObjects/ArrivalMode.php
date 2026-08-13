<?php

namespace App\Modules\Reception\Domain\ValueObjects;

/**
 * How the patient physically arrived, recorded on the ArrivalEvent — distinct
 * from AppointmentStatus, which describes the visit's clinical-workflow stage.
 * SCHEDULED_CHECKIN is checking in against a pre-existing scheduled
 * appointment; WALK_IN and EMERGENCY both create the appointment and check it
 * in atomically (reports/patient-arrival-checkin-modernization-plan.md
 * Phase 1, replacing the two-sequential-call pattern named in
 * reports/patient-arrival-checkin-audit.md §4).
 */
enum ArrivalMode: string
{
    case SCHEDULED_CHECKIN = 'scheduled_checkin';
    case WALK_IN = 'walk_in';
    case EMERGENCY = 'emergency';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
