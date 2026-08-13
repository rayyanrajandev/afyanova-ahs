<?php

namespace App\Modules\Billing\Domain\Services;

interface AdmissionLookupServiceInterface
{
    public function isValidForPatient(string $admissionId, string $patientId): bool;

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $admissionId): ?array;
}
