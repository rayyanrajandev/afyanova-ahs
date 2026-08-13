<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\ValueObjects\InventoryProcurementRequestStatus;
use Illuminate\Support\Str;

class ListInventoryProcurementRequestsUseCase
{
    public function __construct(private readonly InventoryProcurementRequestRepositoryInterface $inventoryProcurementRequestRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, InventoryProcurementRequestStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'requestNumber' => 'request_number',
            'status' => 'status',
            'neededBy' => 'needed_by',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'createdAt';
        $sortBy = $sortMap[$sortBy] ?? 'created_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $itemId = isset($filters['itemId']) ? trim((string) $filters['itemId']) : null;
        $itemId = $itemId === '' || ! Str::isUuid($itemId) ? null : $itemId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->inventoryProcurementRequestRepository->search(
            query: $query,
            status: $status,
            itemId: $itemId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
