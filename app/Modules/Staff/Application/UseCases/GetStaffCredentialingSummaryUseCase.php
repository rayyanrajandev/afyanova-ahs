<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Application\Services\StaffCredentialingSummaryResolver;
use App\Modules\Staff\Domain\Repositories\StaffProfessionalRegistrationRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;

class GetStaffCredentialingSummaryUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffRegulatoryProfileRepositoryInterface $staffRegulatoryProfileRepository,
        private readonly StaffProfessionalRegistrationRepositoryInterface $staffProfessionalRegistrationRepository,
        private readonly StaffCredentialingSummaryResolver $summaryResolver,
    ) {}

    public function execute(string $staffProfileId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $regulatoryProfile = $this->staffRegulatoryProfileRepository->findByStaffProfileId($staffProfileId);
        $registrations = $this->staffProfessionalRegistrationRepository->listAllByStaffProfileId($staffProfileId);

        return $this->summaryResolver->resolve(
            staffProfile: $profile,
            regulatoryProfile: $regulatoryProfile,
            registrations: $registrations,
        );
    }
}
