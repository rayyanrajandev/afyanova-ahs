<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Services\UserLookupServiceInterface;

class SearchEligibleStaffUsersUseCase
{
    public function __construct(
        private readonly UserLookupServiceInterface $userLookupService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function execute(?string $query, int $limit = 10): array
    {
        return $this->userLookupService->searchEligibleUsers($query, $limit);
    }
}
