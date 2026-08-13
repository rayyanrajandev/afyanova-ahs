<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffDocumentRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $query,
        ?string $documentType,
        ?string $status,
        ?string $verificationStatus,
        ?string $expiresFrom,
        ?string $expiresTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;

    /**
     * @param  array<int, string>  $staffProfileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listByStaffProfileIds(array $staffProfileIds, ?string $status = null): array;
}
