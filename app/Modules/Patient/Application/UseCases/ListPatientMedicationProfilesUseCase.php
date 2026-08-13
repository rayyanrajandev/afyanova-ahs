<?php

namespace App\Modules\Patient\Application\UseCases;

use App\Modules\Patient\Domain\Repositories\PatientMedicationProfileRepositoryInterface;
use App\Modules\Patient\Domain\Repositories\PatientRepositoryInterface;

class ListPatientMedicationProfilesUseCase
{
    public function __construct(
        private readonly PatientRepositoryInterface $patientRepository,
        private readonly PatientMedicationProfileRepositoryInterface $patientMedicationProfileRepository,
    ) {}

    public function execute(string $patientId, array $filters): ?array
    {
        if ($this->patientRepository->findById($patientId) === null) {
            return null;
        }

        return $this->patientMedicationProfileRepository->listByPatientId(
            patientId: $patientId,
            status: isset($filters['status']) ? trim((string) $filters['status']) ?: null : null,
            page: max((int) ($filters['page'] ?? 1), 1),
            perPage: min(max((int) ($filters['perPage'] ?? 25), 1), 100),
        );
    }
}
