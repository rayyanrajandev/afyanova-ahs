<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
