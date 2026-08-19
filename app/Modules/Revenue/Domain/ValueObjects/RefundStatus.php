<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

enum RefundStatus: string
{
    case REQUESTED = 'requested';
    case APPROVED = 'approved';
    case PAID = 'paid';
    case REJECTED = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
