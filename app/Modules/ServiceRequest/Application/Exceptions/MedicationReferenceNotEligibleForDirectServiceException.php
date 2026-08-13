<?php

namespace App\Modules\ServiceRequest\Application\Exceptions;

use RuntimeException;

/**
 * Thrown when a Direct Service pharmacy item's referencePharmacyOrderId
 * fails any of the required checks: missing, belongs to a different
 * patient, isn't dispensed, is for a different catalog item, or the
 * catalog item isn't flagged refillable_without_prescription.
 */
class MedicationReferenceNotEligibleForDirectServiceException extends RuntimeException
{
}
