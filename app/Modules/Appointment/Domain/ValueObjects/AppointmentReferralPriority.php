<?php

namespace App\Modules\Appointment\Domain\ValueObjects;

enum AppointmentReferralPriority: string
{
    case ROUTINE = 'routine';
    case URGENT = 'urgent';
    case CRITICAL = 'critical';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $priority): string => $priority->value, self::cases());
    }
}

