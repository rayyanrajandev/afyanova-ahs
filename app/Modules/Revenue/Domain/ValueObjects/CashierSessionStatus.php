<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

enum CashierSessionStatus: string
{
    case OPEN = 'open';

    /** Counted, but the variance needs a second person before it can close. */
    case PENDING_APPROVAL = 'pending_approval';

    case CLOSED = 'closed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function acceptsPayments(): bool
    {
        return $this === self::OPEN;
    }
}
