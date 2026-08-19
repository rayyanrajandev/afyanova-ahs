<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * Why a charge is cleared for delivery.
 *
 * Recording the basis rather than a bare boolean is what makes the prepaid rule
 * auditable: "this patient was seen without paying" is a legitimate outcome in
 * a hospital, and the question that matters afterwards is always *on whose
 * authority*.
 */
enum AuthorizationBasis: string
{
    /** Money received and allocated to the charge. */
    case PAYMENT = 'payment';

    /** A supervisor wrote the charge off before service. */
    case WAIVER = 'waiver';

    /** Clinical override: treat now, reconcile later. */
    case EMERGENCY = 'emergency';

    /**
     * A payer authorized the service instead of the patient paying.
     *
     * Reserved and unreachable in this phase — nothing can produce it. It
     * exists so that adding an insurer is a new authorization policy rather
     * than a schema change.
     */
    case PAYER_AUTHORIZATION = 'payer_authorization';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Bases a person grants directly, and must therefore justify.
     */
    public function requiresReason(): bool
    {
        return $this === self::WAIVER || $this === self::EMERGENCY;
    }

    public function isImplemented(): bool
    {
        return $this !== self::PAYER_AUTHORIZATION;
    }
}
