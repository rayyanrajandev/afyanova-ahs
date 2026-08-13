<?php

namespace App\Modules\Staff\Domain\ValueObjects;

enum StaffRegulatorCode: string
{
    case MCT = 'mct';
    case TNMC = 'tnmc';
    case HLPC = 'hlpc';
    case PC = 'pc';
    case OTHER = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $code): string => $code->value, self::cases());
    }
}
