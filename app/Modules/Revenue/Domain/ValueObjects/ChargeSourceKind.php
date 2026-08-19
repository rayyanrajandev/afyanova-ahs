<?php

namespace App\Modules\Revenue\Domain\ValueObjects;

/**
 * What clinical thing a charge is for.
 *
 * Reuses the `source_workflow_kind` vocabulary already established across this
 * codebase, so a charge addresses its clinical source the same way the rest of
 * the platform does, and "is this order paid for?" stays a single indexed
 * lookup on (kind, id).
 */
enum ChargeSourceKind: string
{
    case CONSULTATION = 'consultation';
    case LABORATORY_ORDER = 'laboratory_order';
    case RADIOLOGY_ORDER = 'radiology_order';
    case PHARMACY_ORDER = 'pharmacy_order';
    case CLINICAL_PROCEDURE_ORDER = 'clinical_procedure_order';

    /** Raised at the counter with no clinical order behind it. */
    case MANUAL = 'manual';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Whether this phase raises charges of this kind.
     *
     * Consultation and manual only. The order-driven kinds are defined now so
     * the schema, the unique index and the authorization reader do not change
     * when each workspace's gate is built.
     */
    public function isImplemented(): bool
    {
        return $this === self::CONSULTATION || $this === self::MANUAL;
    }

    /**
     * Whether a charge of this kind points at exactly one clinical record, and
     * so must never be duplicated.
     */
    public function requiresSourceReference(): bool
    {
        return $this !== self::MANUAL;
    }
}
