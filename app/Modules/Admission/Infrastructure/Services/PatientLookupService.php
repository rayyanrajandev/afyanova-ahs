<?php

namespace App\Modules\Admission\Infrastructure\Services;

use App\Modules\Admission\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class PatientLookupService implements PatientLookupServiceInterface
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function findAdmissionEligibilityById(string $patientId): ?array
    {
        $patient = $this->patientRepository->findById($patientId);

        if ($patient === null) {
            return null;
        }

        return [
            'status' => $patient['status'] ?? null,
            'gender' => $patient['gender'] ?? null,
        ];
    }
}
