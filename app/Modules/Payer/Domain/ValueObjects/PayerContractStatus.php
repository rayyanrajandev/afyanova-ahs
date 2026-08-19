<?php

namespace App\Modules\Payer\Domain\ValueObjects;

enum PayerContractStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case RETIRED = 'retired';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
