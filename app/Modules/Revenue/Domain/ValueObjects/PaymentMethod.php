<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * How money arrived.
 *
 * Only CASH is implemented. The rest are declared so that adding a tender is a
 * new branch at the counter and a driver, not a schema change — payments and
 * allocations are already method-agnostic, and an insurer settlement is simply
 * a payment with method INSURANCE_SETTLEMENT allocated to the same charges.
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case MOBILE_MONEY = 'mobile_money';
    case CARD = 'card';
    case BANK_TRANSFER = 'bank_transfer';
    case GEPG = 'gepg';
    case INSURANCE_SETTLEMENT = 'insurance_settlement';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function isImplemented(): bool
    {
        return match ($this) {
            self::CASH,
            self::MOBILE_MONEY,
            self::BANK_TRANSFER,
            self::GEPG => true,
            default => false,
        };
    }

    /**
     * Whether this tender involves physical custody, and therefore belongs to
     * a drawer session that someone counts at the end of a shift.
     */
    public function requiresCashierSession(): bool
    {
        return $this === self::CASH;
    }

    /**
     * Whether the payer hands over an amount that may exceed what is owed,
     * with the difference given back.
     */
    public function supportsTendering(): bool
    {
        return $this === self::CASH;
    }
}
