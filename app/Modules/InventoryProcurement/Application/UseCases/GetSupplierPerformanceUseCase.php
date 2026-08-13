<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventorySupplierLeadTimeRepositoryInterface;

class GetSupplierPerformanceUseCase
{
    public function __construct(
        private readonly InventorySupplierLeadTimeRepositoryInterface $leadTimeRepository,
    ) {}

    public function execute(string $supplierId, ?string $itemId = null): array
    {
        $avgLeadTime = $this->leadTimeRepository->averageLeadTime($supplierId, $itemId);
        $avgFulfillment = $this->leadTimeRepository->averageFulfillmentRate($supplierId, $itemId);

        $recentDeliveries = $this->leadTimeRepository->listBySupplier($supplierId, 1, 10);

        return [
            'supplierId' => $supplierId,
            'itemId' => $itemId,
            'avgLeadTimeDays' => $avgLeadTime,
            'avgFulfillmentRate' => $avgFulfillment,
            'recentDeliveries' => $recentDeliveries['data'] ?? [],
        ];
    }
}
