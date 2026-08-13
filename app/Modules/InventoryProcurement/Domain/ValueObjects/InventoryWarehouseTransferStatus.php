<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryWarehouseTransferStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case PACKED = 'packed';
    case IN_TRANSIT = 'in_transit';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_APPROVAL => 'Pending Approval',
            self::APPROVED => 'Approved',
            self::PACKED => 'Packed',
            self::IN_TRANSIT => 'In Transit',
            self::RECEIVED => 'Received',
            self::CANCELLED => 'Cancelled',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * @return array<string, array<string>>
     */
    public static function allowedTransitions(): array
    {
        return [
            self::DRAFT->value => [self::PENDING_APPROVAL->value, self::CANCELLED->value],
            self::PENDING_APPROVAL->value => [self::APPROVED->value, self::REJECTED->value, self::CANCELLED->value],
            self::APPROVED->value => [self::PACKED->value, self::IN_TRANSIT->value, self::CANCELLED->value],
            self::PACKED->value => [self::IN_TRANSIT->value, self::CANCELLED->value],
            self::IN_TRANSIT->value => [self::RECEIVED->value],
            self::RECEIVED->value => [],
            self::CANCELLED->value => [],
            self::REJECTED->value => [self::DRAFT->value],
        ];
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
