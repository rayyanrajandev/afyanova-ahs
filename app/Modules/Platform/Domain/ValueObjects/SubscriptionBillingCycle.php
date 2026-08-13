<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum SubscriptionBillingCycle: string
{
    case MONTHLY = 'monthly';
    case QUARTERLY = 'quarterly';
    case ANNUAL = 'annual';
    case CUSTOM = 'custom';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $cycle): string => $cycle->value, self::cases());
    }
}
