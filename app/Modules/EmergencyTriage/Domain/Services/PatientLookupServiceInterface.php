<?php

namespace App\Modules\EmergencyTriage\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
