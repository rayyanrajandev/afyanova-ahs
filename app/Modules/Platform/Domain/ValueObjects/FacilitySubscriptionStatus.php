<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum FacilitySubscriptionStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case PAST_DUE = 'past_due';
    case GRACE_PERIOD = 'grace_period';
    case SUSPENDED = 'suspended';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function allowsAccess(string $status): bool
    {
        return in_array($status, [
            self::TRIAL->value,
            self::ACTIVE->value,
            self::GRACE_PERIOD->value,
        ], true);
    }

    public static function requiresReason(string $status): bool
    {
        return in_array($status, [
            self::PAST_DUE->value,
            self::SUSPENDED->value,
            self::CANCELLED->value,
        ], true);
    }
}
