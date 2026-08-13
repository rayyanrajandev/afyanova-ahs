<?php

namespace App\Modules\ClinicalProcedure\Domain\Services;

interface PatientLookupServiceInterface
{
    public function patientExists(string $patientId): bool;
}
