<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryVenClassification: string
{
    case VITAL = 'vital';
    case ESSENTIAL = 'essential';
    case NON_ESSENTIAL = 'non_essential';

    public function label(): string
    {
        return match ($this) {
            self::VITAL => 'Vital',
            self::ESSENTIAL => 'Essential',
            self::NON_ESSENTIAL => 'Non-Essential',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
