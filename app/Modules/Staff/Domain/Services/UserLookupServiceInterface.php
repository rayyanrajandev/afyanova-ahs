<?php

namespace App\Modules\Staff\Domain\Services;

interface UserLookupServiceInterface
{
    public function userExists(int $userId): bool;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchEligibleUsers(?string $query, int $limit = 10): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findEligibleUserById(int $userId): ?array;
}
