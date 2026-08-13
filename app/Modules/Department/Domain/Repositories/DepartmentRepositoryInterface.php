<?php

namespace App\Modules\Department\Domain\Repositories;

interface DepartmentRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByCodeInScope(
        string $code,
        ?string $tenantId,
        ?string $facilityId,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $status,
        ?string $serviceType,
        ?int $managerUserId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $serviceType,
        ?int $managerUserId,
    ): array;

    public function listAppointmentableOptions(): array;

    public function findActiveByName(string $name): ?array;
}

