<?php

namespace App\Modules\Radiology\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
