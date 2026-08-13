<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierRepositoryInterface;

class GetInventorySupplierUseCase
{
    public function __construct(private readonly InventorySupplierRepositoryInterface $inventorySupplierRepository) {}

    public function execute(string $id): ?array
    {
        return $this->inventorySupplierRepository->findById($id);
    }
}

