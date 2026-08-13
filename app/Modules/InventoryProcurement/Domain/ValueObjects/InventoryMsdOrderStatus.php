<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryMsdOrderStatus: string
{
    case DRAFT = 'draft';
    case SUBMITTED = 'submitted';
    case CONFIRMED = 'confirmed';
    case PARTIALLY_FULFILLED = 'partially_fulfilled';
    case DISPATCHED = 'dispatched';
    case DELIVERED = 'delivered';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::SUBMITTED => 'Submitted to MSD',
            self::CONFIRMED => 'Confirmed by MSD',
            self::PARTIALLY_FULFILLED => 'Partially Fulfilled',
            self::DISPATCHED => 'Dispatched',
            self::DELIVERED => 'Delivered',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
