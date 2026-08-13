<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientAllergyRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class ListPatientAllergiesUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientAllergyRepositoryInterface $patientAllergyRepository,
    ) {}

    public function execute(string $patientId, array $filters): ?array
    {
        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        return $this->patientAllergyRepository->listByPatientId(
            patientId: $patientId,
            status: isset($filters['status']) ? trim((string) $filters['status']) ?: null : null,
            page: max((int) ($filters['page'] ?? 1), 1),
            perPage: min(max((int) ($filters['perPage'] ?? 25), 1), 100),
        );
    }
}
