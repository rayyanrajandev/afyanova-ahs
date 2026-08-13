<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface InventoryDepartmentRequisitionLineRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function listByRequisitionId(string $requisitionId): array;

    public function deleteByRequisitionId(string $requisitionId): int;
}
