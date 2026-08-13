<?php

namespace App\Support\ClinicalOrders;

enum ClinicalOrderEntryState: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $state): string => $state->value, self::cases());
    }
}
