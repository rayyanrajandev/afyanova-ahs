<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffDocumentStatus: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}

