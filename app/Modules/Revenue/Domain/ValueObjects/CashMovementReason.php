<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * Cash entering or leaving a drawer other than by taking payment.
 *
 * Without these, expected cash is wrong by the first time anyone banks a
 * float, and every close after that shows a variance nobody can explain.
 */
enum CashMovementReason: string
{
    case FLOAT_TOP_UP = 'float_top_up';
    case BANKING_DROP = 'banking_drop';
    case PETTY_CASH = 'petty_cash';
    case CORRECTION = 'correction';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Which way the money goes. A top-up adds to the drawer; banking, petty
     * cash and corrections take out of it.
     */
    public function direction(): string
    {
        return $this === self::FLOAT_TOP_UP ? 'in' : 'out';
    }
}
