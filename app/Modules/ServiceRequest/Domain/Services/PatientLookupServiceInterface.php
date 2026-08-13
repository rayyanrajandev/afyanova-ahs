<?php

namespace App\Modules\ServiceRequest\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
