<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryItemRepositoryInterface;
use App\Modules\Pos\Application\Support\PharmacyOtcCatalogSupport;
use App\Modules\Pos\Infrastructure\Services\InStockPharmacyOtcCatalogSearchService;
use App\Modules\InventoryProcurement\Domain\Services\InventoryUnitConversionService;

class ListPharmacyOtcCatalogUseCase
{
    public function __construct(
        private readonly InStockPharmacyOtcCatalogSearchService $inStockPharmacyOtcCatalogSearchService,
        private readonly InventoryItemRepositoryInterface $inventoryItemRepository,
        private readonly InventoryBatchStockService $inventoryBatchStockService,
        private readonly PharmacyOtcCatalogSupport $pharmacyOtcCatalogSupport,
        private readonly InventoryUnitConversionService $unitConversionService,
    ) {}

    public function execute(array $filters): array
    {
        $result = $this->inStockPharmacyOtcCatalogSearchService->execute($filters);

        $rows = [];

        foreach ($result['data'] as $catalogItem) {
            if (! is_array($catalogItem)) {
                continue;
            }

            $inventoryItem = $this->inventoryItemRepository->findBestActiveMatchByCodeOrName(
                $catalogItem['code'] ?? null,
                $catalogItem['name'] ?? null,
            );
            $otcContext = $this->pharmacyOtcCatalogSupport->otcContext($catalogItem);

            $availability = $inventoryItem === null
                ? null
                : $this->inventoryBatchStockService->availability(
                    (string) $inventoryItem['id'],
                    now(),
                    $inventoryItem['default_warehouse_id'] ?? null,
                );

            if ($inventoryItem !== null && (float) ($availability['availableQuantity'] ?? 0) <= 0) {
                continue;
            }

            $units = [];
            if ($inventoryItem !== null) {
                try {
                    $units = $this->unitConversionService->listSellableUnits((string) $inventoryItem['id']);
                } catch (\Throwable) {
                    $units = [];
                }
            }

            $rows[] = array_merge($catalogItem, [
                'inventory_item' => $inventoryItem === null
                    ? null
                    : array_merge($inventoryItem, [
                        'available_stock' => $availability['availableQuantity'] ?? null,
                        'stock_state' => $availability['stockState'] ?? $this->pharmacyOtcCatalogSupport->stockState($inventoryItem),
                        'batch_tracking_mode' => $availability['trackingMode'] ?? 'untracked',
                        'blocked_batch_quantity' => $availability['blockedQuantity'] ?? 0,
                    ]),
                'units' => $units,
                'stock_state' => $availability['stockState'] ?? $this->pharmacyOtcCatalogSupport->stockState($inventoryItem),
                'available_quantity' => $availability['availableQuantity'] ?? ($inventoryItem['current_stock'] ?? null),
                'dosage_form' => $otcContext['dosageForm'],
                'strength' => $otcContext['strength'],
                'review_mode' => $otcContext['reviewMode'],
                'otc_eligible' => $otcContext['otcEligible'],
                'otc_eligibility_reason' => $otcContext['otcEligibilityReason'],
                'otc_unit_price' => $otcContext['unitPrice'],
                'otc_unit_price_source' => $otcContext['unitPriceSource'],
            ]);
        }

        return [
            'data' => $rows,
            'meta' => $result['meta'],
        ];
    }
}
