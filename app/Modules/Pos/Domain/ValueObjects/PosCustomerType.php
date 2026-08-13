<?php

namespace App\Modules\Pos\Domain\ValueObjects;

enum PosCustomerType: string
{
    case ANONYMOUS = 'anonymous';
    case PATIENT = 'patient';
    case STAFF = 'staff';
    case VISITOR = 'visitor';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
