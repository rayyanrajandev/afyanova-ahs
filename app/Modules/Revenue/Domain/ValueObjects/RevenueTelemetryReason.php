<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * Why a Revenue path fell open. Kept as an enum rather than a free string so a
 * reconciliation query can group by cause without pattern-matching prose.
 */
enum RevenueTelemetryReason: string
{
    /**
     * The configured catalogue item does not resolve. This is the one that hid
     * the dead consultation gate: config named CONSULT-GENERAL-OPD, the seeded
     * catalogue held 237 items and none of them was it.
     */
    case NO_ITEM = 'no_item';

    /** The payer class has no settlement path in this phase. */
    case PAYER_UNIMPLEMENTED = 'payer_unimplemented';

    /** The item resolved but carries no active price book entry. */
    case NO_PRICE = 'no_price';

    /** Something threw. The message is carried in `detail`. */
    case EXCEPTION = 'exception';
}
