<?php

namespace App\Modules\ServiceRequest\Domain\ValueObjects;

enum ServiceRequestItemType: string
{
    case LABORATORY_TEST = 'laboratory_test';
    case PHARMACY_MEDICATION = 'pharmacy_medication';
    case RADIOLOGY_STUDY = 'radiology_study';
    case THEATRE_PROCEDURE = 'theatre_procedure';
    case CLINICAL_PROCEDURE = 'clinical_procedure';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
