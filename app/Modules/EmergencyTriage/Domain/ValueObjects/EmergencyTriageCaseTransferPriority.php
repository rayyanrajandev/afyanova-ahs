<?php

namespace App\Modules\EmergencyTriage\Domain\ValueObjects;

enum EmergencyTriageCaseTransferPriority: string
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
