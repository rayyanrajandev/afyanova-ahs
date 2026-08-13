<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryWarehouseRepositoryInterface;

class GetInventoryWarehouseUseCase
{
    public function __construct(private readonly InventoryWarehouseRepositoryInterface $inventoryWarehouseRepository) {}

    public function execute(string $id): ?array
    {
        return $this->inventoryWarehouseRepository->findById($id);
    }
}

