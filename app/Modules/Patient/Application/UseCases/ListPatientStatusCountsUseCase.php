<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class ListPatientStatusCountsUseCase
{
    public function __construct(private readonly PatientRepositoryInterface $patientRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->patientRepository->statusCounts($query);
    }
}
