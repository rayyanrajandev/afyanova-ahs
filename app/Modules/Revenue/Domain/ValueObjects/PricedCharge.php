<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * The outcome of pricing one line: what it costs, which tariff said so, and
 * whether pricing actually succeeded.
 *
 * `pricingStatus` is deliberately part of the result rather than an exception.
 * A missing tariff must not stop a patient being registered — the charge is
 * still raised, flagged unpriced, and shows up at the counter for someone to
 * resolve. Throwing here would mean a facility that forgot to price one
 * service cannot admit patients.
 */
final readonly class PricedCharge
{
    public function __construct(
        public string $chargeableItemId,
        public ?string $priceBookEntryId,
        public Money $unitPrice,
        public float $quantity,
        public Money $grossAmount,
        public Money $discountAmount,
        public ?string $discountReason,
        public Money $taxAmount,
        public Money $netAmount,
        public string $pricingStatus,
    ) {}

    public function isPriced(): bool
    {
        return $this->pricingStatus === 'priced';
    }
}
