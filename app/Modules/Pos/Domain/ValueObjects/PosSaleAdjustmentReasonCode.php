<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosSaleAdjustmentReasonCode: string
{
    case ERROR_CORRECTION = 'error_correction';
    case CUSTOMER_RETURN = 'customer_return';
    case WRONG_ITEM = 'wrong_item';
    case DUPLICATE_SALE = 'duplicate_sale';
    case PRICING_ERROR = 'pricing_error';
    case QUALITY_ISSUE = 'quality_issue';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
