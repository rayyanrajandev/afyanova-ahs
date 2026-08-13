<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Application\Services\InventoryBatchStockService;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryProcurementRequestRepositoryInterface;

class GetShortageQueueUseCase
{
    public function __construct(
        private readonly InventoryDepartmentRequisitionRepositoryInterface $requisitionRepository,
        private readonly InventoryDepartmentRequisitionLineRepositoryInterface $lineRepository,
        private readonly InventoryProcurementRequestRepositoryInterface $procurementRequestRepository,
        private readonly InventoryBatchStockService $stockService,
    ) {}

    /**
     * Return all partially-issued requisitions enriched with live stock availability.
     *
     * Each requisition entry includes:
     *  - All requisition header fields
     *  - `pendingLines`  – lines where approved_quantity > issued_quantity, each annotated with:
     *      - `pendingQuantity`  (approved − issued)
     *      - `availableQuantity` (live from InventoryBatchStockService)
     *      - `canIssueNow`      (availableQuantity >= pendingQuantity)
     *  - `readyLineCount`   – number of lines that can be issued right now
     *  - `waitingLineCount` – number of lines still short of stock
     *
     * @param  array{q?: string, departmentId?: string, readiness?: 'all'|'ready'|'waiting', page?: int, perPage?: int}  $filters
     */
    public function execute(array $filters): array
    {
        $readiness = in_array($filters['readiness'] ?? '', ['ready', 'waiting'], true)
            ? $filters['readiness']
            : 'all';

        // Fetch all partially_issued requisitions (up to 200 per page; shortage queues
        // are expected to be short in practice).
        $page    = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['perPage'] ?? 50)));

        $result = $this->requisitionRepository->search(
            query:          $filters['q'] ?? null,
            status:         'partially_issued',
            department:     null,
            departmentId:   $filters['departmentId'] ?? null,
            page:           $page,
            perPage:        $perPage,
            sortBy:         'created_at',
            sortDirection:  'asc',        // oldest first so the longest-waiting shows at the top
        );

        // Build per-item availability map (deduplicated to avoid N×M DB calls).
        $itemIds = [];
        $lineIds = [];
        foreach ($result['data'] as $requisition) {
            $lines = $this->lineRepository->listByRequisitionId((string) $requisition['id']);
            foreach ($lines as $line) {
                $pending = (float) ($line['approved_quantity'] ?? 0) - (float) ($line['issued_quantity'] ?? 0);
                if ($pending > 0 && isset($line['item_id'])) {
                    $itemIds[] = (string) $line['item_id'];
                    $lineIds[] = (string) ($line['id'] ?? '');
                }
            }
        }

        $procurementByLineId = $this->procurementRequestRepository
            ->latestBySourceDepartmentRequisitionLineIds($lineIds);

        $availabilityByItemId = [];
        foreach (array_unique($itemIds) as $itemId) {
            try {
                $availabilityByItemId[$itemId] = $this->stockService->availability($itemId);
            } catch (\Throwable) {
                $availabilityByItemId[$itemId] = ['availableQuantity' => 0.0, 'stockState' => 'unknown'];
            }
        }

        // Enrich each requisition.
        $enriched = [];
        $totalReady   = 0;
        $totalWaiting = 0;

        foreach ($result['data'] as $requisition) {
            $allLines = $this->lineRepository->listByRequisitionId((string) $requisition['id']);

            $pendingLines = [];
            $readyLineCount   = 0;
            $waitingLineCount = 0;

            foreach ($allLines as $line) {
                $approved = (float) ($line['approved_quantity'] ?? 0);
                $issued   = (float) ($line['issued_quantity']   ?? 0);
                $pending  = $approved - $issued;

                if ($pending <= 0) {
                    continue;
                }

                $itemId    = (string) ($line['item_id'] ?? '');
                $avail     = $availabilityByItemId[$itemId] ?? ['availableQuantity' => 0.0, 'stockState' => 'unknown'];
                $available = (float) ($avail['availableQuantity'] ?? 0);
                $canIssue  = $available >= $pending;

                $pendingLines[] = array_merge($line, [
                    'pendingQuantity'   => round($pending, 3),
                    'availableQuantity' => round($available, 3),
                    'stockState'        => $avail['stockState'] ?? 'unknown',
                    'canIssueNow'       => $canIssue,
                    'procurementRequest' => $procurementByLineId[(string) ($line['id'] ?? '')] ?? null,
                ]);

                if ($canIssue) {
                    $readyLineCount++;
                } else {
                    $waitingLineCount++;
                }
            }

            if (empty($pendingLines)) {
                // All lines fully satisfied — skip (edge case where status is stale).
                continue;
            }

            if ($readiness === 'ready' && $readyLineCount === 0) {
                continue;
            }

            if ($readiness === 'waiting' && $waitingLineCount === 0) {
                continue;
            }

            $totalReady   += $readyLineCount;
            $totalWaiting += $waitingLineCount;

            $enriched[] = array_merge($requisition, [
                'lines'            => $allLines,
                'pendingLines'     => $pendingLines,
                'readyLineCount'   => $readyLineCount,
                'waitingLineCount' => $waitingLineCount,
            ]);
        }

        return [
            'data'    => $enriched,
            'meta'    => array_merge($result['meta'], [
                'readyLineCount'   => $totalReady,
                'waitingLineCount' => $totalWaiting,
                'readiness'        => $readiness,
            ]),
        ];
    }
}
