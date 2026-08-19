<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * The lifecycle of a single billable service.
 *
 * A charge never moves backwards. An authorized charge that must be undone
 * produces a reversal and a refund; the original charge, its payment and its
 * receipt stay exactly as issued, because that is what an auditor needs to see.
 */
enum ServiceChargeStatus: string
{
    /** Raised but not yet presentable — pricing unresolved, or still being assembled. */
    case DRAFT = 'draft';

    /** Priced and owed. The service it covers must not be provided yet. */
    case PENDING_PAYMENT = 'pending_payment';

    /** Cleared for delivery: paid, waived, or overridden. See AuthorizationBasis. */
    case AUTHORIZED = 'authorized';

    /** The service was delivered. */
    case FULFILLED = 'fulfilled';

    /** Withdrawn before it was ever authorized. */
    case CANCELLED = 'cancelled';

    /** Money was returned after authorization. */
    case REFUNDED = 'refunded';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<string, list<string>>
     */
    public static function allowedTransitions(): array
    {
        return [
            self::DRAFT->value => [
                self::PENDING_PAYMENT->value,
                self::CANCELLED->value,
            ],
            self::PENDING_PAYMENT->value => [
                self::AUTHORIZED->value,
                self::CANCELLED->value,
            ],
            // Authorization can be released again — a payment reversed inside
            // the same drawer session drops the charge back to owing, which is
            // the ordinary correction path at a counter and must not require a
            // refund.
            self::AUTHORIZED->value => [
                self::FULFILLED->value,
                self::PENDING_PAYMENT->value,
                self::REFUNDED->value,
            ],
            self::FULFILLED->value => [
                self::REFUNDED->value,
            ],
            self::CANCELLED->value => [],
            self::REFUNDED->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target->value, self::allowedTransitions()[$this->value] ?? [], true);
    }

    /**
     * Whether the service this charge covers may be provided.
     */
    public function permitsFulfilment(): bool
    {
        return $this === self::AUTHORIZED || $this === self::FULFILLED;
    }

    /**
     * Whether the charge still represents money owed.
     */
    public function isOutstanding(): bool
    {
        return $this === self::DRAFT || $this === self::PENDING_PAYMENT;
    }

    public function isTerminal(): bool
    {
        return $this === self::CANCELLED || $this === self::REFUNDED;
    }
}
