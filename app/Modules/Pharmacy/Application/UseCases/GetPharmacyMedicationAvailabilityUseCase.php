<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;

class GetPharmacyMedicationAvailabilityUseCase
{
    public function __construct(
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(?string $medicationCode, ?string $medicationName, ?string $catalogItemId = null): ?array
    {
        if ($catalogItemId !== null) {
            $linked = $this->inventoryItemRepository->listLinkedByClinicalCatalogIds([$catalogItemId]);
            $item = ! empty($linked) ? $linked[0] : null;
        } else {
            $item = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName($medicationCode, $medicationName);
        }

        if ($item === null) {
            return null;
        }

        return $this->inventoryBatchStockService->enrichItemAvailability($item);
    }
}
