<?php

namespace App\Modules\InventoryProcurement\Domain\Repositories;

interface DepartmentStockBalanceRepositoryInterface
{
    public function findOrCreateBalance(
        string $tenantId,
        string $departmentId,
        string $itemId,
        ?string $batchId,
        ?string $unit,
    ): array;

    public function incrementOnHand(
        string $balanceId,
        float $quantity,
        ?string $unit = null,
    ): array;

    public function decrementOnHand(
        string $balanceId,
        float $quantity,
    ): array;

    public function recordConsumption(
        string $balanceId,
        float $quantity,
    ): array;

    public function recordReturn(
        string $balanceId,
        float $quantity,
    ): array;

    public function recordWastage(
        string $balanceId,
        float $quantity,
    ): array;

    public function findByDepartmentAndItem(
        string $departmentId,
        string $itemId,
    ): ?array;

    public function listByDepartment(
        string $departmentId,
        ?string $search = null,
        int $page = 1,
        int $perPage = 20,
    ): array;

    public function summaryByDepartment(string $departmentId): array;
}
