<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\ClinicalSpecialtyRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class ListStaffProfileSpecialtiesUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly ClinicalSpecialtyRepositoryInterface $clinicalSpecialtyRepository,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function execute(string $staffProfileId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        return $this->clinicalSpecialtyRepository->listByStaffProfileId($staffProfileId);
    }
}

