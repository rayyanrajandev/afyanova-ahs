<?php

namespace App\Modules\Staff\Domain\Repositories;

interface ClinicalPrivilegeCatalogRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsCodeInScope(string $code, ?string $tenantId, ?string $excludeId = null): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $specialtyId,
        ?string $cadreCode,
        ?string $facilityType,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;
}
