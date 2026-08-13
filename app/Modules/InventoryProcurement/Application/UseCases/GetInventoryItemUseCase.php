<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;

class GetInventoryItemUseCase
{
    public function __construct(private readonly InventoryItemRepositoryInterface $inventoryItemRepository) {}

    public function execute(string $id): ?array
    {
        return $this->inventoryItemRepository->findById($id);
    }
}

