<?php

namespace App\Modules\Payer\Application\UseCases;

use App\Modules\Payer\Domain\Repositories\PatientInsuranceRepositoryInterface;

class ListPatientInsuranceRecordsUseCase
{
    public function __construct(
        private readonly PatientInsuranceRepositoryInterface $repository,
    ) {}

    public function execute(string $patientId): array
    {
        return $this->repository->findByPatientId($patientId);
    }
}
