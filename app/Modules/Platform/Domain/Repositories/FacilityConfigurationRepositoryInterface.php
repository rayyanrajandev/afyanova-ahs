<?php

namespace App\Modules\Platform\Domain\Repositories;

interface FacilityConfigurationRepositoryInterface
{
    public function findById(string $id): ?array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $facilityType,
        ?int $ownerUserId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function create(array $attributes): array;

    public function update(string $id, array $attributes): ?array;

    public function existsCodeInTenant(
        string $tenantId,
        string $code,
        ?string $excludeId = null
    ): bool;
}
