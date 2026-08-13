<?php

namespace App\Modules\MedicalRecord\Application\Exceptions;

use RuntimeException;

/**
 * A Direct Service encounter (VisitCategory::DIRECT_SERVICE) has no
 * clinician assessment behind it — it exists only so a direct-to-department
 * request gets proper encounter_id linkage for billing/visit-history. No
 * clinical note can be attached to it.
 */
class ServiceEncounterNotEligibleForMedicalRecordException extends RuntimeException
{
}
