<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\PatientInsuranceRepositoryInterface;

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
