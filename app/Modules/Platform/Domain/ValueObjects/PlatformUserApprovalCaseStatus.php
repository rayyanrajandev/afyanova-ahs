<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum PlatformUserApprovalCaseStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case CANCELLED = 'cancelled';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function statusTransitionValues(): array
    {
        return [
            self::DRAFT->value,
            self::SUBMITTED->value,
            self::CANCELLED->value,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function decisionValues(): array
    {
        return [
            self::APPROVED->value,
            self::REJECTED->value,
        ];
    }
}

