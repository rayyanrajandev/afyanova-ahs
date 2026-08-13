<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffDocumentRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class GetStaffDocumentUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffDocumentRepositoryInterface $staffDocumentRepository,
    ) {}

    public function execute(string $staffProfileId, string $staffDocumentId): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        return $this->staffDocumentRepository->findByIdForStaffProfile(
            staffProfileId: $staffProfileId,
            id: $staffDocumentId,
        );
    }
}

