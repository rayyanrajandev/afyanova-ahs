<?php

namespace App\Modules\Staff\Domain\Repositories;

interface ClinicalSpecialtyRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsCodeInScope(string $code, ?string $tenantId, ?string $excludeId = null): bool;

    public function search(
        ?string $query,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;

    /**
     * @param  array<int, string>  $specialtyIds
     * @return array<int, string>
     */
    public function resolveExistingSpecialtyIdsInScope(array $specialtyIds): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByStaffProfileId(string $staffProfileId): array;

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, int>}
     */
    public function listStaffBySpecialtyId(string $specialtyId, int $page, int $perPage): array;

    /**
     * @param  array<int, array<string, mixed>>  $assignments
     * @return array<int, array<string, mixed>>
     */
    public function syncStaffProfileSpecialties(string $staffProfileId, array $assignments): array;
}

