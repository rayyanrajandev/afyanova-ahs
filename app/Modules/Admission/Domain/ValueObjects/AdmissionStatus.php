<?php

namespace App\Modules\Admission\Domain\ValueObjects;

enum AdmissionStatus: string
{
    case ADMITTED = 'admitted';
    case DISCHARGED = 'discharged';
    case TRANSFERRED = 'transferred';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
