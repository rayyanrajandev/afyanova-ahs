<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryBatchModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDispensingClaimLinkModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetInventoryDashboardKpisUseCase
{
    public function __construct(
        private readonly InventoryBatchModel $batchModel,
        private readonly InventoryItemModel $itemModel,
        private readonly PharmacyOrderModel $pharmacyOrderModel,
        private readonly InventoryDispensingClaimLinkModel $claimModel,
        private readonly ClinicalCatalogItemModel $catalogItemModel,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(): array
    {
        $now = Carbon::now();

        $totalValueQuery = $this->batchModel->newQuery()
            ->leftJoin('inventory_items', 'inventory_batches.item_id', '=', 'inventory_items.id')
            ->where('inventory_items.category', 'pharmaceutical')
            ->where('quantity', '>', 0);
        $this->helper->applyPlatformScopeIfEnabled($totalValueQuery);

        $itemQuery = $this->itemModel->newQuery();
        $this->helper->applyPlatformScopeIfEnabled($itemQuery);
        $itemQuery->where('category', 'pharmaceutical');

        $expiringQuery = $this->batchModel->newQuery()
            ->leftJoin('inventory_items', 'inventory_batches.item_id', '=', 'inventory_items.id')
            ->where('inventory_items.category', 'pharmaceutical')
            ->whereNotNull('inventory_batches.expiry_date')
            ->where('inventory_batches.quantity', '>', 0)
            ->where('inventory_batches.expiry_date', '>', $now)
            ->where('inventory_batches.expiry_date', '<=', $now->copy()->addDays(30));
        $this->helper->applyPlatformScopeIfEnabled($expiringQuery);

        $dispensedTodayQuery = $this->pharmacyOrderModel->newQuery()
            ->whereDate('dispensed_at', $now->toDateString());
        $this->helper->applyPlatformScopeIfEnabled($dispensedTodayQuery);

        $controlledSubQuery = $this->catalogItemModel->newQuery()
            ->where('is_controlled_substance', true)
            ->select('id');

        $controlledQuery = $this->pharmacyOrderModel->newQuery()
            ->whereDate('dispensed_at', $now->toDateString())
            ->whereIn('approved_medicine_catalog_item_id', $controlledSubQuery);
        $this->helper->applyPlatformScopeIfEnabled($controlledQuery);

        $pendingClaimsQuery = $this->claimModel->newQuery()
            ->where('claim_status', 'pending');
        $this->helper->applyPlatformScopeIfEnabled($pendingClaimsQuery);

        return [
            'inventoryValue' => (float) ($totalValueQuery->select(DB::raw('COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) as total'))->value('total') ?? 0),
            'lowStockCount' => (clone $itemQuery)->where('current_stock', '>', 0)->whereColumn('current_stock', '<=', 'reorder_level')->count(),
            'outOfStockCount' => (clone $itemQuery)->where('current_stock', '<=', 0)->count(),
            'expiringIn30Days' => $expiringQuery->count(),
            'dispensedToday' => $dispensedTodayQuery->count(),
            'controlledDrugDispensesToday' => $controlledQuery->count(),
            'pendingInsuranceClaims' => $pendingClaimsQuery->count(),
        ];
    }
}
