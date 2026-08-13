<?php

namespace App\Modules\Radiology\Infrastructure\Services;

use App\Modules\Radiology\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class PatientLookupService implements PatientLookupServiceInterface
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function patientExists(string $patientId): bool
    {
        return $this->patientRepository->findById($patientId) !== null;
    }
}
