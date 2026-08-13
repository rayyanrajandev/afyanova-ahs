<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryStockMovementReason: string
{
    case OPENING_BALANCE = 'opening_balance';
    case PHYSICAL_COUNT_ADJUSTMENT = 'physical_count_adjustment';
    case EXPIRY_WRITE_OFF = 'expiry_write_off';
    case DAMAGED_STOCK = 'damaged_stock';
    case DONATION = 'donation';
    case EMERGENCY_REPLENISHMENT = 'emergency_replenishment';
    case AUDIT_CORRECTION = 'audit_correction';
    case RETURN_TO_SUPPLIER = 'return_to_supplier';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::OPENING_BALANCE => 'Opening Balance',
            self::PHYSICAL_COUNT_ADJUSTMENT => 'Physical Count Adjustment',
            self::EXPIRY_WRITE_OFF => 'Expiry Write-off',
            self::DAMAGED_STOCK => 'Damaged Stock',
            self::DONATION => 'Donation',
            self::EMERGENCY_REPLENISHMENT => 'Emergency Replenishment',
            self::AUDIT_CORRECTION => 'Audit Correction',
            self::RETURN_TO_SUPPLIER => 'Return to Supplier',
            self::OTHER => 'Other',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], self::cases());
    }
}
