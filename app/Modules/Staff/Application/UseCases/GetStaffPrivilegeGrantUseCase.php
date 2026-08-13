<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class GetStaffPrivilegeGrantUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
    ) {}

    public function execute(string $staffProfileId, string $staffPrivilegeGrantId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        return $this->staffPrivilegeGrantRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffPrivilegeGrantId,
        );
    }
}

