<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class GetPatientUseCase
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function execute(string $id): ?array
    {
        return $this->patientRepository->findById($id);
    }
}
