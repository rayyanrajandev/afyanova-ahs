<?php

namespace App\Modules\Platform\Domain\ValueObjects;

/**
 * The single canonical classification of "why is this patient here today,"
 * spanning Reception, Appointment, Encounter, and ServiceRequest. Lives in
 * Platform (a shared/cross-cutting-concern module) rather than any one of
 * those, since no single module owns the concept.
 *
 * Distinct from Reception's ArrivalMode: ArrivalMode records how Reception
 * specifically observed the arrival (its 3 values only cover what the
 * check-in endpoint accepts); VisitCategory is the higher-level
 * classification derived from it, and also covers Direct Service, which
 * never goes through check-in/ArrivalMode at all.
 */
enum VisitCategory: string
{
    case OPD_WALK_IN = 'opd_walk_in';
    case EMERGENCY_WALK_IN = 'emergency_walk_in';
    case SCHEDULED_APPOINTMENT = 'scheduled_appointment';
    case DIRECT_SERVICE = 'direct_service';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $category): string => $category->value, self::cases());
    }

    /**
     * The permission that authorizes starting a visit of this category.
     * OPD/Emergency walk-in additionally require appointment.check-in
     * (see requiredPermissions()) — kept separate because check-in is a
     * second, distinct authorization, not a variant of "create."
     */
    public function requiredPermission(): string
    {
        return match ($this) {
            self::OPD_WALK_IN, self::EMERGENCY_WALK_IN, self::SCHEDULED_APPOINTMENT => 'appointments.create',
            self::DIRECT_SERVICE => 'service.requests.create',
        };
    }

    /**
     * @return array<int, string>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::OPD_WALK_IN, self::EMERGENCY_WALK_IN => [$this->requiredPermission(), 'appointment.check-in'],
            self::SCHEDULED_APPOINTMENT, self::DIRECT_SERVICE => [$this->requiredPermission()],
        };
    }
}
