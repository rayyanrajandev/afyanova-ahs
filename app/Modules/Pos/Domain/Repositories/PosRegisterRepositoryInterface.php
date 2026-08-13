<?php

namespace App\Modules\Pos\Domain\Repositories;

interface PosRegisterRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByRegisterCodeInScope(
        string $registerCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
