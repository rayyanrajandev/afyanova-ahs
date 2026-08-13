<?php

namespace App\Modules\Platform\Domain\Repositories;

interface UserFacilityAssignmentRepositoryInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActiveFacilityScopesByUserId(int $userId): array;
}
