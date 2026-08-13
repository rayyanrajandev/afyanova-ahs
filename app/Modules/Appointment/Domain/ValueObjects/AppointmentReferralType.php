<?php

namespace App\Modules\Appointment\Domain\ValueObjects;

enum AppointmentReferralType: string
{
    case INTERNAL = 'internal';
    case EXTERNAL = 'external';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

