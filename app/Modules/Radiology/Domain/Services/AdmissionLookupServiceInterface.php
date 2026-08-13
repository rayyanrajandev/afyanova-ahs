<?php

namespace App\Modules\Radiology\Domain\Services;

interface AdmissionLookupServiceInterface
{
    public function isValidForPatient(string $admissionId, string $patientId): bool;
}
