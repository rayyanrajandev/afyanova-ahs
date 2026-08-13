<?php

namespace App\Modules\Pos\Domain\Repositories;

interface PosCafeteriaMenuItemRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    /**
     * @param array<int, string> $ids
     * @return array<int, array<string, mixed>>
     */
    public function findByIds(array $ids, bool $activeOnly = false): array;

    public function existsByItemCodeInScope(
        string $itemCode,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $category,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;
}
