<?php

namespace App\Modules\Laboratory\Domain\Services;

interface AdmissionLookupServiceInterface
{
    public function isValidForPatient(string $admissionId, string $patientId): bool;
}
