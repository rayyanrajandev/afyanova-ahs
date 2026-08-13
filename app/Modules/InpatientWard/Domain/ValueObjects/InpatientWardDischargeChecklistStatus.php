<?php

namespace App\Modules\InpatientWard\Domain\ValueObjects;

enum InpatientWardDischargeChecklistStatus: string
{
    case DRAFT = 'draft';
    case READY = 'ready';
    case BLOCKED = 'blocked';
    case COMPLETED = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

