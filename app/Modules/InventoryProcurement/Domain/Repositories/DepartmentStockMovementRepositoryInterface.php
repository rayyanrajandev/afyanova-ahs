<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface DepartmentStockMovementRepositoryInterface
{
    public function create(array $attributes): array;

    public function listByBalanceId(string $balanceId, int $page = 1, int $perPage = 20): array;

    public function listByDepartment(
        string $departmentId,
        ?string $itemId = null,
        ?string $movementType = null,
        int $page = 1,
        int $perPage = 20,
    ): array;
}
