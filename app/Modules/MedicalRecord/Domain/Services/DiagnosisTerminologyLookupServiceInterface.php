<?php

namespace App\Modules\MedicalRecord\Domain\Services;

interface DiagnosisTerminologyLookupServiceInterface
{
    public function hasAnyActiveDiagnosisCodes(): bool;

    public function isActiveDiagnosisCode(string $diagnosisCode): bool;
}
