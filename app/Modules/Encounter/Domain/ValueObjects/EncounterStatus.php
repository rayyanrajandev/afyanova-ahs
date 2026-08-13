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
