<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class GetStaffRegulatoryProfileUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
    ) {}

    public function execute(string $staffProfileId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        return $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
    }
}
