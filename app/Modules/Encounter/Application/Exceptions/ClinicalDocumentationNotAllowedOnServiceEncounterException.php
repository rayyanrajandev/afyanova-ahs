<?php

namespace App\Modules\Encounter\Application\Exceptions;

use RuntimeException;

/**
 * A Direct Service encounter (VisitCategory::DIRECT_SERVICE) is a "Service
 * Encounter" — it exists so orders/invoices get proper encounter_id linkage
 * for visit-history and billing, but no clinician ever assessed the patient
 * on it, so it must never carry a diagnosis, prescription, or clinical note.
 */
class ClinicalDocumentationNotAllowedOnServiceEncounterException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This encounter was created for a direct service request, with no clinician assessment — clinical documentation cannot be attached to it.');
    }
}
