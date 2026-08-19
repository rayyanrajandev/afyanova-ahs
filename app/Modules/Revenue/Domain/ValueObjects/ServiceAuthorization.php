<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * The answer to "may this service be provided?", in a shape a clinical surface
 * can both act on and show a person.
 */
final readonly class ServiceAuthorization
{
    public function __construct(
        public bool $authorized,
        public ?string $chargeId,
        public ?string $chargeNumber,
        public string $status,
        public ?AuthorizationBasis $basis,
        public ?Money $amountDue,
        public ?Money $amountPaid,
        public string $requirement,
    ) {}

    /**
     * No charge was ever raised for this service.
     *
     * Treated as authorized on purpose. A service outside the prepaid rule —
     * anything whose gate has not been switched on yet — must not be blocked
     * by a ledger that was never asked to price it. Turning a gate on means
     * raising charges for that kind, not tightening this default.
     */
    public static function notCharged(): self
    {
        return new self(
            authorized: true,
            chargeId: null,
            chargeNumber: null,
            status: 'not_charged',
            basis: null,
            amountDue: null,
            amountPaid: null,
            requirement: 'No charge applies to this service.',
        );
    }
}
