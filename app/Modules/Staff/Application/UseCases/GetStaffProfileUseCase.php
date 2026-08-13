<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class GetStaffProfileUseCase
{
    public function __construct(private readonly StaffProfileRepositoryInterface $staffProfileRepository) {}

    public function execute(string $id): ?array
    {
        return $this->staffProfileRepository->findById($id);
    }
}
