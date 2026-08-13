<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CrossTenantAdminPatientReadRepositoryInterface
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function searchByTenantId(
        string $tenantId,
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
