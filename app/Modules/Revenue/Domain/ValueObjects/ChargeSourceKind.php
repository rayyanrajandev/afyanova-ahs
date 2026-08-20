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
     * Whether the prepaid gate is switched on for this kind.
     *
     * The enum value is the config key by construction, so a new kind cannot
     * arrive with its gate silently unreadable — and the key is derived in one
     * place rather than retyped at each of the eight sites that ask.
     */
    public function prepaidGateEnabled(): bool
    {
        return (bool) config("revenue.prepaid_required_for.{$this->value}", true);
    }

    /**
     * Human-readable name, for messages a person reads. The enum value is a
     * key ('laboratory_order'); this is prose ('laboratory order').
     */
    public function label(): string
    {
        return match ($this) {
            self::CONSULTATION => 'consultation',
            self::LABORATORY_ORDER => 'laboratory order',
            self::RADIOLOGY_ORDER => 'radiology order',
            self::PHARMACY_ORDER => 'pharmacy order',
            self::CLINICAL_PROCEDURE_ORDER => 'clinical procedure order',
            self::MANUAL => 'counter charge',
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
     * Whether this phase raises charges of this kind.
     *
     * Consultation and manual only. The order-driven kinds are defined now so
     * the schema, the unique index and the authorization reader do not change
     * when each workspace's gate is built.
     */
    public function isImplemented(): bool
    {
        return $this === self::CONSULTATION
            || $this === self::LABORATORY_ORDER
            || $this === self::RADIOLOGY_ORDER
            || $this === self::PHARMACY_ORDER
            || $this === self::CLINICAL_PROCEDURE_ORDER
            || $this === self::MANUAL;
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
