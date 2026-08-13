<?php

namespace App\Modules\ServiceRequest\Infrastructure\Services;

use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;
use App\Modules\ServiceRequest\Domain\Services\PatientLookupServiceInterface;

class PatientLookupService implements PatientLookupServiceInterface
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function patientExists(string $patientId): bool
    {
        return $this->patientRepository->findById($patientId) !== null;
    }
}
