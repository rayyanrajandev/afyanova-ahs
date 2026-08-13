<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Exceptions\InventoryItemNotFoundException;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryStockMovementRepositoryInterface;

class ShowInventoryStockMovementUseCase
{
    public function __construct(
        private readonly InventoryStockMovementRepositoryInterface $inventoryStockMovementRepository,
    ) {}

    public function execute(string $id): array
    {
        $movement = $this->inventoryStockMovementRepository->findById($id);
        if ($movement === null) {
            throw new InventoryItemNotFoundException('Stock movement not found.');
        }

        return $movement;
    }
}
