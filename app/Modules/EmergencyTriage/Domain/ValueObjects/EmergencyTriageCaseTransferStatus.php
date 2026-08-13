<?php

namespace App\Modules\EmergencyTriage\Domain\ValueObjects;

enum EmergencyTriageCaseTransferStatus: string
{
    case REQUESTED = 'requested';
    case ACCEPTED = 'accepted';
    case IN_TRANSIT = 'in_transit';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
