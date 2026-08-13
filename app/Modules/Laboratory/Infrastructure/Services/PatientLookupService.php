<?php

namespace App\Modules\Laboratory\Infrastructure\Services;

use App\Modules\Laboratory\Domain\Services\PatientLookupServiceInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class PatientLookupService implements PatientLookupServiceInterface
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function patientExists(string $patientId): bool
    {
        return $this->patientRepository->findById($patientId) !== null;
    }
}
