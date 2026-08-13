<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;

class ListClinicalSpecialtyAssignedStaffUseCase
{
    public function __construct(private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository) {}

    public function execute(string $specialtyId, array $filters): ?array
    {
        $specialty = $this->clinicalSpecialtyRepository->findById($specialtyId);
        if (! $specialty) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 10), 1), 50);

        return $this->clinicalSpecialtyRepository->listStaffBySpecialtyId($specialtyId, $page, $perPage);
    }
}
