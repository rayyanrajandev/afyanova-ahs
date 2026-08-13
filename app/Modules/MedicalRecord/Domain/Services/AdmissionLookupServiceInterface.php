<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface AdmissionLookupServiceInterface
{
    public function isValidForPatient(string $admissionId, string $patientId): bool;
}
