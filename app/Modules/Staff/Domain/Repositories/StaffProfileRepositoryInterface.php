<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffProfileRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    /**
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    public function findByIds(array $ids): array;

    public function update(string $id, array $attributes): ?array;

    public function existsByEmployeeNumber(string $employeeNumber): bool;

    public function findByUserId(string $userId): ?array;

    /**
     * @return array<int, string>
     */
    public function listDistinctDepartments(): array;

    /**
     * @return array{page:int, position:int}|null
     */
    public function locateInSearch(
        string $staffProfileId,
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        bool $clinicalOnly,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): ?array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        bool $clinicalOnly,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection,
        bool $physicianOnly = false
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $department,
        ?string $employmentType
    ): array;
}
