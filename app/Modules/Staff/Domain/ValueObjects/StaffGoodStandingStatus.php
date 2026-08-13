<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffGoodStandingStatus: string
{
    case UNKNOWN = 'unknown';
    case IN_GOOD_STANDING = 'in_good_standing';
    case RESTRICTED = 'restricted';
    case WITHDRAWN = 'withdrawn';
    case PENDING = 'pending';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
