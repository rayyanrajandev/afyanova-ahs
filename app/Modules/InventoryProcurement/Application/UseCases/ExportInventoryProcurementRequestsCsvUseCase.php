<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryProcurementRequestResponseTransformer;
use Illuminate\Support\Str;

class ExportInventoryProcurementRequestsCsvUseCase
{
    private const EXPORT_PAGE_SIZE = 100;

    private const MAX_EXPORT_ROWS = 20000;

    private const COLUMNS = [
        'requestNumber', 'purchaseOrderNumber', 'status', 'itemCode', 'itemName', 'itemCategory',
        'requestedQuantity', 'orderedQuantity', 'receivedQuantity', 'itemUnit', 'unitCostEstimate',
        'receivedUnitCost', 'totalCostEstimate', 'supplierName', 'neededBy', 'approvedAt', 'orderedAt',
        'receivedAt', 'notes', 'createdAt', 'updatedAt',
    ];

    public function __construct(private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository) {}

    /**
     * @return array{columns: array<int, string>, rows: array<int, array<string, mixed>>}
     */
    public function execute(array $filters): array
    {
        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InventoryProcurementRequestStatus::values(), true)) {
            $status = null;
        }

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $itemId = isset($filters['itemId']) ? trim((string) $filters['itemId']) : null;
        $itemId = $itemId === '' || ! Str::isUuid($itemId) ? null : $itemId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $rows = [];
        $page = 1;
        do {
            $result = $this->inventoryProcurementRequestRepository->search(
                query: $query,
                status: $status,
                itemId: $itemId,
                fromDateTime: $fromDateTime,
                toDateTime: $toDateTime,
                page: $page,
                perPage: self::EXPORT_PAGE_SIZE,
                sortBy: 'created_at',
                sortDirection: 'desc',
            );

            foreach ($result['data'] as $request) {
                $rows[] = InventoryProcurementRequestResponseTransformer::transform($request);
                if (count($rows) >= self::MAX_EXPORT_ROWS) {
                    break 2;
                }
            }

            $lastPage = (int) ($result['meta']['lastPage'] ?? $page);
            $page++;
        } while ($page <= $lastPage);

        return [
            'columns' => self::COLUMNS,
            'rows' => $rows,
        ];
    }
}
