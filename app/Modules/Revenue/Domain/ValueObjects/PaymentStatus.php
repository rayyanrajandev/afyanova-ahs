<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * Payments are insert-only. A mistake is corrected by a linked reversal, never
 * by editing or deleting the original — the receipt the patient is holding
 * must stay explainable.
 */
enum PaymentStatus: string
{
    case RECORDED = 'recorded';
    case REVERSED = 'reversed';

    /** The negative counterpart written to undo another payment. */
    case REVERSAL = 'reversal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether this payment still counts towards what a patient has paid and
     * what is in the drawer.
     */
    public function countsTowardsBalance(): bool
    {
        return $this === self::RECORDED;
    }
}
