<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CrossTenantAdminStaffProfileReadRepositoryInterface
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
