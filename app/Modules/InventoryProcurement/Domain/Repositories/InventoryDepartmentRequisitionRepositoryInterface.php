<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryDepartmentRequisitionRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function search(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $departmentId,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function nextRequisitionNumber(): string;
}
