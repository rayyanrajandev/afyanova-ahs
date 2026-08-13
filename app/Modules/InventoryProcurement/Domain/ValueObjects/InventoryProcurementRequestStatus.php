<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryProcurementRequestStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case ORDERED = 'ordered';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
