<?php

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryItemCategory;

enum ClinicalCatalogType: string
{
    case LAB_TEST = 'lab_test';
    case RADIOLOGY_PROCEDURE = 'radiology_procedure';
    case THEATRE_PROCEDURE = 'theatre_procedure';
    case CLINICAL_PROCEDURE = 'clinical_procedure';
    case FORMULARY_ITEM = 'formulary_item';
    case DIAGNOSIS_CODE = 'diagnosis_code';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function defaultBillingServiceType(): ?string
    {
        return match ($this) {
            self::LAB_TEST => 'laboratory',
            self::RADIOLOGY_PROCEDURE => 'radiology',
            self::THEATRE_PROCEDURE => 'theatre',
            self::CLINICAL_PROCEDURE => 'procedure',
            self::FORMULARY_ITEM => 'pharmacy',
            self::DIAGNOSIS_CODE => null,
        };
    }

    public function supportsConsumptionRecipes(): bool
    {
        return match ($this) {
            self::LAB_TEST, self::RADIOLOGY_PROCEDURE, self::THEATRE_PROCEDURE, self::CLINICAL_PROCEDURE => true,
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public function eligibleInventoryCategories(): array
    {
        return match ($this) {
            self::LAB_TEST => [
                InventoryItemCategory::LABORATORY->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
            ],
            self::RADIOLOGY_PROCEDURE => [
                InventoryItemCategory::RADIOLOGY->value,
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
            ],
            self::THEATRE_PROCEDURE => [
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
                InventoryItemCategory::SURGICAL_INSTRUMENT->value,
            ],
            self::CLINICAL_PROCEDURE => [
                InventoryItemCategory::MEDICAL_CONSUMABLE->value,
                InventoryItemCategory::PPE->value,
                InventoryItemCategory::OTHER->value,
            ],
            default => [],
        };
    }
}
