<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffProfessionalLicenseStatus: string
{
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';
    case REVOKED = 'revoked';
    case PENDING = 'pending';
    case INACTIVE = 'inactive';
    case NOT_REQUIRED = 'not_required';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
