<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffProfessionalRegistrationRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByIdForStaffProfile(string $staffProfileId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsDuplicateForStaffProfile(
        string $staffProfileId,
        string $regulatorCode,
        string $registrationNumber,
        ?string $excludeId = null,
    ): bool;

    public function searchByStaffProfileId(
        string $staffProfileId,
        ?string $regulatorCode,
        ?string $registrationStatus,
        ?string $licenseStatus,
        ?string $verificationStatus,
        ?string $expiresFrom,
        ?string $expiresTo,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAllByStaffProfileId(string $staffProfileId): array;

    /**
     * @param  array<int, string>  $staffProfileIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function listAllByStaffProfileIds(array $staffProfileIds): array;

    public function searchCredentialingAlerts(
        ?string $query,
        ?string $facilityId,
        ?string $regulatorCode,
        ?string $cadreCode,
        ?string $alertType,
        ?string $alertState,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
    ): array;
}
