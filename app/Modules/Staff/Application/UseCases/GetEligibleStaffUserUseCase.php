<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Services\UserLookupServiceInterface;

class GetEligibleStaffUserUseCase
{
    public function __construct(
        private readonly UserLookupServiceInterface $userLookupService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(int $userId): ?array
    {
        return $this->userLookupService->findEligibleUserById($userId);
    }
}
