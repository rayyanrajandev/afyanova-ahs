<?php

namespace App\Modules\Appointment\Domain\Services;

interface PatientLookupServiceInterface
{
    public function isActivePatient(string $patientId): bool;
}
