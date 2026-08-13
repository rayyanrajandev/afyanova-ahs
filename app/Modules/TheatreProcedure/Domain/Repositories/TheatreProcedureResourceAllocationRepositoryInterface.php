<?php

namespace App\Modules\TheatreProcedure\Domain\Repositories;

interface TheatreProcedureResourceAllocationRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByProcedureAndId(string $theatreProcedureId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function searchByProcedure(
        string $theatreProcedureId,
        ?string $query,
        ?string $resourceType,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCountsByProcedure(
        string $theatreProcedureId,
        ?string $query,
        ?string $resourceType,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;

    public function hasOverlapForResource(
        string $resourceType,
        string $resourceReference,
        string $plannedStartAt,
        string $plannedEndAt,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeAllocationId = null
    ): bool;
}
