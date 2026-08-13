<?php

namespace App\Modules\InventoryProcurement\Domain\ValueObjects;

enum InventoryItemCategory: string
{
    case PHARMACEUTICAL = 'pharmaceutical';
    case MEDICAL_CONSUMABLE = 'medical_consumable';
    case LABORATORY = 'laboratory';
    case SURGICAL_INSTRUMENT = 'surgical_instrument';
    case MEDICAL_EQUIPMENT = 'medical_equipment';
    case LINEN_TEXTILE = 'linen_textile';
    case FOOD_NUTRITION = 'food_nutrition';
    case OFFICE_ADMIN = 'office_admin';
    case CLEANING_SANITATION = 'cleaning_sanitation';
    case BLOOD_PRODUCT = 'blood_product';
    case PPE = 'ppe';
    case DENTAL = 'dental';
    case RADIOLOGY = 'radiology';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PHARMACEUTICAL => 'Pharmaceutical',
            self::MEDICAL_CONSUMABLE => 'Medical Consumable',
            self::LABORATORY => 'Laboratory Reagent & Supply',
            self::SURGICAL_INSTRUMENT => 'Surgical Instrument',
            self::MEDICAL_EQUIPMENT => 'Medical Equipment',
            self::LINEN_TEXTILE => 'Linen & Textile',
            self::FOOD_NUTRITION => 'Food & Nutrition',
            self::OFFICE_ADMIN => 'Office & Admin Supply',
            self::CLEANING_SANITATION => 'Cleaning & Sanitation',
            self::BLOOD_PRODUCT => 'Blood Product',
            self::PPE => 'Personal Protective Equipment',
            self::DENTAL => 'Dental',
            self::RADIOLOGY => 'Radiology',
            self::OTHER => 'Other',
        };
    }

    public function formTemplate(): string
    {
        return match ($this) {
            self::PHARMACEUTICAL => 'pharmaceutical',
            self::BLOOD_PRODUCT, self::LABORATORY, self::FOOD_NUTRITION => 'expiry_sensitive',
            self::SURGICAL_INSTRUMENT, self::MEDICAL_EQUIPMENT, self::DENTAL, self::RADIOLOGY => 'specialist_equipment',
            default => 'general_supply',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PHARMACEUTICAL => 'Medicine stock master with dispensing, clinical classification, and reimbursement mapping fields.',
            self::BLOOD_PRODUCT => 'Expiry-sensitive and cold-chain inventory. Capture handling defaults here and batch details on first receipt.',
            self::LABORATORY => 'Expiry-sensitive reagent and laboratory supply inventory with storage-handling requirements.',
            self::FOOD_NUTRITION => 'Expiry-sensitive nutrition inventory with storage defaults and replenishment controls.',
            self::SURGICAL_INSTRUMENT, self::MEDICAL_EQUIPMENT, self::DENTAL, self::RADIOLOGY => 'Specialist stock master for procurement and replenishment defaults. Keep serial, calibration, and maintenance details in the equipment workflow.',
            default => 'General stock item with supplier, warehouse, barcode, and stock-threshold defaults.',
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
     * @return array<string, string>
     */
    public static function labelMap(): array
    {
        $map = [];
        foreach (self::cases() as $case) {
            $map[$case->value] = $case->label();
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function optionMetadata(): array
    {
        return array_map(
            static fn (self $case): array => $case->metadata(),
            self::cases(),
        );
    }

    public function requiresExpiryTracking(): bool
    {
        return match ($this) {
            self::PHARMACEUTICAL, self::BLOOD_PRODUCT, self::LABORATORY, self::FOOD_NUTRITION => true,
            default => false,
        };
    }

    public function requiresColdChain(): bool
    {
        return match ($this) {
            self::BLOOD_PRODUCT => true,
            default => false,
        };
    }

    public function isControlledSubstanceEligible(): bool
    {
        return $this === self::PHARMACEUTICAL;
    }

    public function supportsMedicineDetails(): bool
    {
        return $this === self::PHARMACEUTICAL;
    }

    public function supportsStorageFields(): bool
    {
        return match ($this) {
            self::PHARMACEUTICAL, self::BLOOD_PRODUCT, self::LABORATORY, self::FOOD_NUTRITION => true,
            default => false,
        };
    }

    public function supportsClinicalClassification(): bool
    {
        return match ($this) {
            self::PHARMACEUTICAL, self::MEDICAL_CONSUMABLE, self::LABORATORY, self::BLOOD_PRODUCT, self::DENTAL, self::RADIOLOGY => true,
            default => false,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label(),
            'template' => $this->formTemplate(),
            'description' => $this->description(),
            'requiresExpiryTracking' => $this->requiresExpiryTracking(),
            'requiresColdChain' => $this->requiresColdChain(),
            'controlledSubstanceEligible' => $this->isControlledSubstanceEligible(),
            'supportsMedicineDetails' => $this->supportsMedicineDetails(),
            'supportsStorageFields' => $this->supportsStorageFields(),
            'supportsClinicalClassification' => $this->supportsClinicalClassification(),
        ];
    }
}
