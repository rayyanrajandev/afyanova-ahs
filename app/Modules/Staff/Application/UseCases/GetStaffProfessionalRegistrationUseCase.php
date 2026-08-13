<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class GetStaffProfessionalRegistrationUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
    ) {}

    public function execute(string $staffProfileId, string $staffProfessionalRegistrationId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        return $this->staffProfessionalRegistrationRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffProfessionalRegistrationId,
        );
    }
}
