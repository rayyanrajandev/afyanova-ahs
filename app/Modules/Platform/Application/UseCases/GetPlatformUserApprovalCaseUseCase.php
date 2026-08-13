<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformUserApprovalCaseRepositoryInterface;

class GetPlatformUserApprovalCaseUseCase
{
    public function __construct(private readonly PlatformUserApprovalCaseRepositoryInterface $platformUserApprovalCaseRepository) {}

    public function execute(string $id): ?array
    {
        return $this->platformUserApprovalCaseRepository->findCaseById($id);
    }
}

