<?php

namespace App\Modules\EmergencyTriage\Domain\Services;

interface AdmissionLookupServiceInterface
{
    public function isValidForPatient(string $admissionId, string $patientId): bool;
}
