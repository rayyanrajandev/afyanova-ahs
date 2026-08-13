<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryStockMovementModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetMedicineConsumptionUseCase
{
    public function __construct(
        private readonly InventoryStockMovementModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $granularity = $filters['granularity'] ?? 'daily';
        $days = (int) ($filters['days'] ?? 30);
        $since = Carbon::now()->subDays($days)->startOfDay();

        $query = $this->model->newQuery()
            ->leftJoin('inventory_items', 'inventory_stock_movements.item_id', '=', 'inventory_items.id')
            ->where('inventory_items.category', 'pharmaceutical')
            ->where('movement_type', 'issue')
            ->where('occurred_at', '>=', $since);

        $this->helper->applyPlatformScopeIfEnabled($query);

        $dateFormat = match ($granularity) {
            'weekly' => "DATE_FORMAT(occurred_at, '%x-W%v')",
            'monthly' => "DATE_FORMAT(occurred_at, '%Y-%m')",
            default => "DATE(occurred_at)",
        };

        $results = $query
            ->select(DB::raw("{$dateFormat} as period"), DB::raw('SUM(ABS(quantity_delta)) as total_consumed'), DB::raw('COUNT(*) as movement_count'))
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'totalConsumed' => (float) $row->total_consumed,
                'movementCount' => (int) $row->movement_count,
            ])
            ->all();

        return ['data' => $results];
    }
}
