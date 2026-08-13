<?php

namespace App\Modules\Billing\Infrastructure\Services;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Billing\Domain\Services\AdmissionLookupServiceInterface;

class AdmissionLookupService implements AdmissionLookupServiceInterface
{
    public function __construct(private readonly AdmissionRepositoryInterface $admissionRepository) {}

    public function isValidForPatient(string $admissionId, string $patientId): bool
    {
        $admission = $this->findById($admissionId);

        return $admission !== null && ($admission['patient_id'] ?? null) === $patientId;
    }

    public function findById(string $admissionId): ?array
    {
        return $this->admissionRepository->findById($admissionId);
    }
}
