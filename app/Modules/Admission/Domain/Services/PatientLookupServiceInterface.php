<?php

namespace App\Modules\Admission\Domain\Services;

interface PatientLookupServiceInterface
{
    /**
     * @return array{status: ?string, gender: ?string}|null
     */
    public function findAdmissionEligibilityById(string $patientId): ?array;
}
