<?php

namespace App\Modules\ServiceRequest\Domain\ValueObjects;

enum ServiceRequestServiceType: string
{
    case LABORATORY = 'laboratory';
    case PHARMACY = 'pharmacy';
    case RADIOLOGY = 'radiology';
    case THEATRE_PROCEDURE = 'theatre_procedure';
    case CLINICAL_PROCEDURE = 'clinical_procedure';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The `linked_order_type` value each Create*OrderUseCase passes to
     * LinkServiceRequestToClinicalOrderUseCase::complete() when a ticket is
     * auto-completed by creating its destination clinical order. Single
     * source of truth so a manual completion (UpdateServiceRequestStatusUseCase)
     * can derive the same value instead of a caller guessing it — theatre
     * doesn't follow the "{serviceType}_order" pattern the other three do.
     */
    public function linkedOrderType(): string
    {
        return match ($this) {
            self::LABORATORY => 'laboratory_order',
            self::PHARMACY => 'pharmacy_order',
            self::RADIOLOGY => 'radiology_order',
            self::THEATRE_PROCEDURE => 'theatre_procedure',
            self::CLINICAL_PROCEDURE => 'clinical_procedure_order',
        };
    }
}
