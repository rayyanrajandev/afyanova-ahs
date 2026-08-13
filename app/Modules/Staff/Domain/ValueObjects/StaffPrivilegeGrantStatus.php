<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffPrivilegeGrantStatus: string
{
    case REQUESTED = 'requested';
    case UNDER_REVIEW = 'under_review';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case SUSPENDED = 'suspended';
    case RETIRED = 'retired';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    public static function workflowOrder(string $status): int
    {
        return match ($status) {
            self::REQUESTED->value => 1,
            self::UNDER_REVIEW->value => 2,
            self::APPROVED->value => 3,
            self::ACTIVE->value => 4,
            self::SUSPENDED->value => 5,
            self::RETIRED->value => 6,
            default => 0,
        };
    }

    public static function requiresReason(string $status): bool
    {
        return in_array($status, [
            self::SUSPENDED->value,
            self::RETIRED->value,
        ], true);
    }
}
