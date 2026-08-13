<?php

namespace App\Modules\MedicalRecord\Infrastructure\Services;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\MedicalRecord\Domain\Services\AdmissionLookupServiceInterface;

class AdmissionLookupService implements AdmissionLookupServiceInterface
{
    public function __construct(private readonly AdmissionRepositoryInterface $admissionRepository) {}

    public function isValidForPatient(string $admissionId, string $patientId): bool
    {
        $admission = $this->admissionRepository->findById($admissionId);

        return $admission !== null && ($admission['patient_id'] ?? null) === $patientId;
    }
}
