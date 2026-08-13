<?php

namespace App\Modules\Billing\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
