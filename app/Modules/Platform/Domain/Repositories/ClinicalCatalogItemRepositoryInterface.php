<?php

namespace App\Modules\Platform\Domain\Repositories;

interface ClinicalCatalogItemRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByCodeInScope(
        string $catalogType,
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        string $catalogType,
        ?string $query,
        ?string $status,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        ?array $ids = null,
    ): array;

    public function findIdByCodeInScope(
        string $catalogType,
        string $code,
        ?string $tenantId,
        ?string $facilityId,
    ): ?string;

    /**
     * @param  list<string>  $catalogTypes
     * @return array<int, array<string, mixed>>
     */
    public function searchActiveForSync(array $catalogTypes): array;

    public function statusCounts(
        string $catalogType,
        ?string $query,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
    ): array;

    public function typeCounts(
        ?string $query,
        ?string $departmentId,
        ?string $category,
        ?string $dosageForm = null,
    ): array;
}
