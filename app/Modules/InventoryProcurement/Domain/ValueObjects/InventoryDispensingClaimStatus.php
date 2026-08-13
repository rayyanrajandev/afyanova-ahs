<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryDispensingClaimStatus: string
{
    case PENDING = 'pending';
    case LINKED = 'linked';
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case PARTIALLY_APPROVED = 'partially_approved';
    case REJECTED = 'rejected';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::LINKED => 'Linked to Claim',
            self::SUBMITTED => 'Submitted',
            self::APPROVED => 'Approved',
            self::PARTIALLY_APPROVED => 'Partially Approved',
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
