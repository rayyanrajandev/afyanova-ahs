<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAnalyticsController extends Controller
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    /**
     * Consumption trends — aggregate issue movements by day/week/month.
     */
    public function consumptionTrends(Request $request): JsonResponse
    {
        $granularity = $request->query('granularity', 'daily'); // daily|weekly|monthly
        $days = (int) $request->query('days', 30);
        $itemId = $request->query('itemId');

        $since = Carbon::now()->subDays($days)->startOfDay();

        $query = InventoryStockMovementModel::query()
            ->where('movement_type', 'issue')
            ->where('occurred_at', '>=', $since);

        $this->applyPlatformScopeIfEnabled($query);

        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        $dateFormat = match ($granularity) {
            'weekly' => "DATE_FORMAT(occurred_at, '%x-W%v')",
            'monthly' => "DATE_FORMAT(occurred_at, '%Y-%m')",
            default => "DATE(occurred_at)",
        };

        $results = $query
            ->select(DB::raw("{$dateFormat} as period"), DB::raw('SUM(ABS(quantity_delta)) as total_issued'), DB::raw('COUNT(*) as movement_count'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'totalIssued' => (float) $row->total_issued,
                'movementCount' => (int) $row->movement_count,
            ])
            ->all();

        return response()->json(['data' => $results]);
    }

    /**
     * ABC/VEN classification matrix — items grouped by ABC × VEN.
     */
    public function abcVenMatrix(Request $request): JsonResponse
    {
        $query = InventoryItemModel::query()->where('status', 'active');
        $this->applyPlatformScopeIfEnabled($query);

        // Get counts per ABC × VEN cell
        $matrix = (clone $query)
            ->select(
                DB::raw("COALESCE(abc_classification, 'unclassified') as abc"),
                DB::raw("COALESCE(ven_classification, 'unclassified') as ven"),
                DB::raw('COUNT(*) as item_count'),
                DB::raw('SUM(current_stock) as total_stock'),
            )
            ->groupBy(
                DB::raw("COALESCE(abc_classification, 'unclassified')"),
                DB::raw("COALESCE(ven_classification, 'unclassified')"),
            )
            ->get()
            ->map(fn ($row) => [
                'abc' => $row->abc,
                'ven' => $row->ven,
                'itemCount' => (int) $row->item_count,
                'totalStock' => (float) $row->total_stock,
            ])
            ->all();

        // Also return top items per classification for detail drill-down
        $topItems = $query
            ->select('id', 'item_code', 'item_name', 'abc_classification', 'ven_classification', 'current_stock', 'reorder_level', 'category')
            ->orderByDesc('current_stock')
            ->limit(100)
            ->get()
            ->map(fn ($item) => $item->toArray())
            ->all();

        return response()->json([
            'matrix' => $matrix,
            'topItems' => $topItems,
        ]);
    }

    /**
     * Expiry wastage tracking — expired and near-expiry batches.
     */
    public function expiryWastage(Request $request): JsonResponse
    {
        $warningDays = (int) $request->query('warningDays', 90);
        $criticalDays = (int) $request->query('criticalDays', 30);
        $now = Carbon::now();

        $batchQuery = InventoryBatchModel::query()
            ->whereNotNull('expiry_date')
            ->where('quantity', '>', 0)
            ->where('status', 'active');

        $this->applyPlatformScopeIfEnabled($batchQuery);

        $batches = $batchQuery->get();

        $expired = [];
        $critical = [];
        $warning = [];

        foreach ($batches as $batch) {
            $expiryDate = Carbon::parse($batch->expiry_date);
            $daysUntilExpiry = $now->diffInDays($expiryDate, false);
            $entry = [
                'id' => $batch->id,
                'itemId' => $batch->item_id,
                'batchNumber' => $batch->batch_number,
                'expiryDate' => $batch->expiry_date->toDateString(),
                'quantity' => (float) $batch->quantity,
                'unitCost' => $batch->unit_cost ? (float) $batch->unit_cost : null,
                'estimatedWasteValue' => $batch->unit_cost ? round((float) $batch->quantity * (float) $batch->unit_cost, 2) : null,
                'daysUntilExpiry' => (int) $daysUntilExpiry,
                'warehouseId' => $batch->warehouse_id,
            ];

            if ($daysUntilExpiry < 0) {
                $expired[] = $entry;
            } elseif ($daysUntilExpiry <= $criticalDays) {
                $critical[] = $entry;
            } elseif ($daysUntilExpiry <= $warningDays) {
                $warning[] = $entry;
            }
        }

        $summary = [
            'expiredCount' => count($expired),
            'expiredTotalValue' => array_sum(array_column($expired, 'estimatedWasteValue')),
            'criticalCount' => count($critical),
            'criticalTotalValue' => array_sum(array_column($critical, 'estimatedWasteValue')),
            'warningCount' => count($warning),
            'warningTotalValue' => array_sum(array_column($warning, 'estimatedWasteValue')),
        ];

        return response()->json([
            'summary' => $summary,
            'expired' => $expired,
            'critical' => $critical,
            'warning' => $warning,
        ]);
    }

    /**
     * Stock turnover rate — consumption vs average stock.
     */
    public function stockTurnover(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 90);
        $since = Carbon::now()->subDays($days)->startOfDay();

        // Total issues per item in the period
        $issueQuery = InventoryStockMovementModel::query()
            ->where('movement_type', 'issue')
            ->where('occurred_at', '>=', $since);
        $this->applyPlatformScopeIfEnabled($issueQuery);

        $issuesByItem = $issueQuery
            ->select('item_id', DB::raw('SUM(ABS(quantity_delta)) as total_issued'))
            ->groupBy('item_id')
            ->pluck('total_issued', 'item_id');

        // Current stock per item
        $itemQuery = InventoryItemModel::query()
            ->where('status', 'active')
            ->where('current_stock', '>', 0);
        $this->applyPlatformScopeIfEnabled($itemQuery);

        $items = $itemQuery
            ->select('id', 'item_code', 'item_name', 'current_stock', 'category', 'abc_classification', 'ven_classification')
            ->get();

        $turnoverData = [];
        foreach ($items as $item) {
            $issued = (float) ($issuesByItem[$item->id] ?? 0);
            $currentStock = (float) $item->current_stock;
            $avgStock = $currentStock; // simplified; real would use daily snapshots
            $turnoverRate = $avgStock > 0 ? round($issued / $avgStock, 2) : 0;
            $daysOfStock = $issued > 0 ? round(($currentStock / ($issued / $days)), 1) : null;

            $turnoverData[] = [
                'itemId' => $item->id,
                'itemCode' => $item->item_code,
                'itemName' => $item->item_name,
                'category' => $item->category,
                'abcClassification' => $item->abc_classification,
                'venClassification' => $item->ven_classification,
                'currentStock' => $currentStock,
                'totalIssued' => $issued,
                'turnoverRate' => $turnoverRate,
                'daysOfStock' => $daysOfStock,
            ];
        }

        // Sort by turnover rate descending
        usort($turnoverData, fn ($a, $b) => $b['turnoverRate'] <=> $a['turnoverRate']);

        return response()->json(['data' => $turnoverData]);
    }

    private function applyPlatformScopeIfEnabled(Builder $query): void
    {
        // 'inventory_procurement_platform_scoping' isn't a registered flag
        // key (the real ones are 'platform.multi_facility_scoping'/
        // 'platform.multi_tenant_isolation') — this silently returned false
        // regardless of the real flags' state, so scoping never fired for
        // any of this controller's 4 analytics endpoints.
        try {
            if ($this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
                || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
                $this->platformScopeQueryApplier->apply($query);
            }
        } catch (\Throwable) {
            // Silently skip scoping if feature flag resolution fails
        }
    }
}
