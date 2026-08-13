<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffPrivilegeGrantRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsDuplicateInScope(
        string $staffProfileId,
        string $facilityId,
        string $specialtyId,
        string $privilegeCode,
        ?string $excludeId = null
    ): bool;

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $query,
        ?string $facilityId,
        ?string $specialtyId,
        ?string $status,
        ?string $grantedFrom,
        ?string $grantedTo,
        ?string $reviewDueFrom,
        ?string $reviewDueTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    /**
     * @param  array<int, string>  $staffProfileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listByStaffProfileIds(array $staffProfileIds, ?string $status = null): array;
}
