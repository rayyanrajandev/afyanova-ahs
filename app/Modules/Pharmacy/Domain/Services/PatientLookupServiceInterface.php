<?php

namespace App\Modules\Pharmacy\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
