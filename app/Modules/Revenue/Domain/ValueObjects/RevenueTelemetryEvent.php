<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * The things Revenue does silently, made countable.
 *
 * Every case here is a path that is *deliberately* fail-open: a missing tariff,
 * an unsettleable payer, a Revenue fault during a clinical cancellation. Failing
 * open is the right call in a hospital — never block care for a billing problem
 * — but failing open without a signal is how the prepaid consultation gate sat
 * dead in every environment while 25 Revenue tests stayed green (2026-08-19
 * workspace maturity audit, finding C2).
 *
 * These are anomalies, not states. A gate that is switched off in configuration
 * is not recorded: that is a decision someone made, it is true on every call,
 * and burying the real signals under it would defeat the purpose.
 */
enum RevenueTelemetryEvent: string
{
    /** A service was provided or ordered, and nothing was billed for it. */
    case CHARGE_NOT_RAISED = 'charge.not_raised';

    /** A charge exists but carries no price, so a cashier cannot settle it. */
    case CHARGE_UNPRICED = 'charge.unpriced';

    /**
     * An order was cancelled but its pending charge survived. The patient is
     * still billed for a service that will never be provided.
     */
    case CHARGE_CANCEL_FAILED = 'charge.cancel_failed';

    /**
     * The money cleared but the visit did not advance — the patient paid and is
     * still sitting in AWAITING_PAYMENT.
     */
    case PROMOTION_FAILED = 'promotion.failed';

    /**
     * True when the event means a patient is stuck or mischarged right now, as
     * opposed to a revenue figure being wrong after the fact. Alerting should
     * page on these and report on the rest.
     */
    public function blocksAPatient(): bool
    {
        return match ($this) {
            self::PROMOTION_FAILED, self::CHARGE_UNPRICED => true,
            self::CHARGE_NOT_RAISED, self::CHARGE_CANCEL_FAILED => false,
        };
    }
}
