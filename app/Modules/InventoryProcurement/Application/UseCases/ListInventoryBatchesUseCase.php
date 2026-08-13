<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryBatchRepositoryInterface;
use App\Modules\InventoryProcurement\Application\Services\InventoryStockReservationService;

class ListInventoryBatchesUseCase
{
    public function __construct(
        private readonly InventoryBatchRepositoryInterface $batchRepository,
        private readonly InventoryStockReservationService $inventoryStockReservationService,
    ) {}

    public function execute(array $filters): array
    {
        $itemId = $filters['itemId'] ?? null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['perPage'] ?? 25)));
        $status = $filters['status'] ?? null;

        if ($itemId) {
            return $this->withReservationSummary(
                $this->batchRepository->listByItemId($itemId, $page, $perPage, $status),
            );
        }

        $withinDays = (int) ($filters['expiringWithinDays'] ?? 0);
        if ($withinDays > 0) {
            return $this->withReservationSummary(
                $this->batchRepository->expiringBatches($withinDays, $page, $perPage),
            );
        }

        if (($filters['expired'] ?? false)) {
            return $this->withReservationSummary(
                $this->batchRepository->expiredBatches($page, $perPage),
            );
        }

        return ['data' => [], 'meta' => ['currentPage' => 1, 'lastPage' => 1, 'perPage' => $perPage, 'total' => 0]];
    }

    /**
     * @param  array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}  $result
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function withReservationSummary(array $result): array
    {
        $reservedByBatch = $this->inventoryStockReservationService->activeBatchReservationQuantities(
            collect($result['data'] ?? [])
                ->pluck('id')
                ->map(static fn (mixed $id): string => trim((string) $id))
                ->filter()
                ->values()
                ->all(),
        );

        $result['data'] = array_map(function (array $batch) use ($reservedByBatch): array {
            $reservedQuantity = round((float) ($reservedByBatch[(string) ($batch['id'] ?? '')] ?? 0), 3);
            $quantity = round((float) ($batch['quantity'] ?? 0), 3);

            $batch['reserved_quantity'] = $reservedQuantity;
            $batch['available_quantity'] = round(max($quantity - $reservedQuantity, 0), 3);

            return $batch;
        }, $result['data'] ?? []);

        return $result;
    }
}
