<?php

namespace App\Modules\Platform\Domain\ValueObjects;

use App\Modules\Laboratory\Infrastructure\Models\LaboratoryOrderModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Radiology\Infrastructure\Models\RadiologyOrderModel;
use App\Modules\ClinicalProcedure\Infrastructure\Models\ClinicalProcedureOrderModel;
use App\Modules\TheatreProcedure\Infrastructure\Models\TheatreProcedureModel;

enum ClinicalSourceKind: string
{
    case LABORATORY_ORDER = 'laboratory_order';
    case PHARMACY_ORDER = 'pharmacy_order';
    case RADIOLOGY_ORDER = 'radiology_order';
    case CLINICAL_PROCEDURE_ORDER = 'clinical_procedure_order';
    case THEATRE_PROCEDURE = 'theatre_procedure';
    case APPOINTMENT_CONSULTATION = 'appointment_consultation';
    case ADMISSION_BED_DAY = 'admission_bed_day';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * Resolve from a source workflow kind string, supporting legacy POS names.
     */
    public static function fromWorkflowKind(string $kind): ?self
    {
        return match ($kind) {
            'laboratory_order' => self::LABORATORY_ORDER,
            'pharmacy_order', 'pharmacy_prescription' => self::PHARMACY_ORDER,
            'radiology_order' => self::RADIOLOGY_ORDER,
            'clinical_procedure_order' => self::CLINICAL_PROCEDURE_ORDER,
            'theatre_procedure', 'procedure' => self::THEATRE_PROCEDURE,
            'appointment_consultation' => self::APPOINTMENT_CONSULTATION,
            'admission_bed_day' => self::ADMISSION_BED_DAY,
            default => null,
        };
    }

    /**
     * @return array<int, self>
     */
    public static function orderKinds(): array
    {
        return [self::LABORATORY_ORDER, self::PHARMACY_ORDER, self::RADIOLOGY_ORDER, self::CLINICAL_PROCEDURE_ORDER, self::THEATRE_PROCEDURE];
    }

    public function modelClass(): string
    {
        return match ($this) {
            self::LABORATORY_ORDER => LaboratoryOrderModel::class,
            self::PHARMACY_ORDER => PharmacyOrderModel::class,
            self::RADIOLOGY_ORDER => RadiologyOrderModel::class,
            self::CLINICAL_PROCEDURE_ORDER => ClinicalProcedureOrderModel::class,
            self::THEATRE_PROCEDURE => TheatreProcedureModel::class,
            self::APPOINTMENT_CONSULTATION => throw new \LogicException('Consultations are not order-shaped.'),
            self::ADMISSION_BED_DAY => throw new \LogicException('Bed-day charges are not order-shaped.'),
        };
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 3: the domain-specific segment
     * of a per-domain cutover flag, e.g. "pricing.engine.v2.laboratory".
     * Short/readable names matching the migration plan's own wording, not
     * necessarily identical to this enum's value.
     */
    public function pricingEngineDomainFlag(): string
    {
        return match ($this) {
            self::LABORATORY_ORDER => 'laboratory',
            self::PHARMACY_ORDER => 'pharmacy',
            self::RADIOLOGY_ORDER => 'radiology',
            self::CLINICAL_PROCEDURE_ORDER => 'clinical_procedure',
            self::THEATRE_PROCEDURE => 'theatre',
            self::APPOINTMENT_CONSULTATION => 'consultation',
            self::ADMISSION_BED_DAY => 'bed_day',
        };
    }

    /**
     * PricingEngine_Migration_Plan.md Phase 5: true once a domain's legacy
     * string-match pricing path has been deleted outright (not just
     * flag-gated) -- charge capture for that kind is then unconditionally
     * priced via chargeable_item_id, with no fallback and no flag check.
     * Flipped on domain-by-domain as each one's removal lands.
     */
    public function isLegacyPricingRemoved(): bool
    {
        return match ($this) {
            self::LABORATORY_ORDER => true,
            self::RADIOLOGY_ORDER => true,
            self::CLINICAL_PROCEDURE_ORDER => true,
            self::THEATRE_PROCEDURE => true,
            self::PHARMACY_ORDER => true,
            self::APPOINTMENT_CONSULTATION => true,
            self::ADMISSION_BED_DAY => true,
            default => false,
        };
    }

    public function catalogFk(): string
    {
        return match ($this) {
            self::LABORATORY_ORDER => 'lab_test_catalog_item_id',
            self::PHARMACY_ORDER => 'approved_medicine_catalog_item_id',
            self::RADIOLOGY_ORDER => 'radiology_procedure_catalog_item_id',
            self::CLINICAL_PROCEDURE_ORDER => 'clinical_procedure_catalog_item_id',
            self::THEATRE_PROCEDURE => 'theatre_procedure_catalog_item_id',
            self::APPOINTMENT_CONSULTATION => throw new \LogicException('Consultations have no catalog FK.'),
            self::ADMISSION_BED_DAY => throw new \LogicException('Bed-day charges have no catalog FK.'),
        };
    }

    /**
     * @return array<int, string>
     */
    public function posStatuses(): array
    {
        return match ($this) {
            self::LABORATORY_ORDER => ['ordered'],
            self::PHARMACY_ORDER => ['pending'],
            self::RADIOLOGY_ORDER => ['ordered'],
            self::CLINICAL_PROCEDURE_ORDER => ['ordered'],
            self::THEATRE_PROCEDURE => ['planned'],
            self::APPOINTMENT_CONSULTATION => throw new \LogicException('Consultations are not POS-eligible.'),
            self::ADMISSION_BED_DAY => throw new \LogicException('Bed-day charges are not POS-eligible.'),
        };
    }

    public function posEntryState(): ?string
    {
        return match ($this) {
            self::PHARMACY_ORDER => 'active',
            default => null,
        };
    }

    public function posExcludeEnteredInError(): bool
    {
        return match ($this) {
            self::LABORATORY_ORDER, self::PHARMACY_ORDER, self::RADIOLOGY_ORDER, self::CLINICAL_PROCEDURE_ORDER => true,
            self::THEATRE_PROCEDURE => false,
            self::APPOINTMENT_CONSULTATION => throw new \LogicException('Consultations are not POS-eligible.'),
            self::ADMISSION_BED_DAY => throw new \LogicException('Bed-day charges are not POS-eligible.'),
        };
    }
}
