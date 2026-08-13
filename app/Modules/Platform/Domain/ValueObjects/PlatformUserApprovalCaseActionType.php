<?php

namespace App\Modules\Platform\Domain\ValueObjects;

enum PlatformUserApprovalCaseActionType: string
{
    case STATUS_CHANGE = 'status_change';
    case ROLE_CHANGE = 'role_change';
    case FACILITY_CHANGE = 'facility_change';
    case BULK_CHANGE = 'bulk_change';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}

