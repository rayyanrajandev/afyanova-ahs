<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetPrescriptionTrendsUseCase
{
    public function __construct(
        private readonly PharmacyOrderModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $granularity = $filters['granularity'] ?? 'daily';
        $days = (int) ($filters['days'] ?? 90);
        $since = Carbon::now()->subDays($days)->startOfDay();

        $query = $this->model->newQuery()
            ->where('created_at', '>=', $since);

        $this->helper->applyPlatformScopeIfEnabled($query);

        $dateFormat = match ($granularity) {
            'weekly' => "DATE_FORMAT(created_at, '%x-W%v')",
            'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
            default => "DATE(created_at)",
        };

        $results = $query
            ->select(
                DB::raw("{$dateFormat} as period"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw("SUM(CASE WHEN status = 'dispensed' THEN 1 ELSE 0 END) as dispensed_count"),
                DB::raw('COALESCE(SUM(quantity_prescribed), 0) as total_prescribed'),
            )
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn ($row) => [
                'period' => $row->period,
                'orderCount' => (int) $row->order_count,
                'dispensedCount' => (int) $row->dispensed_count,
                'totalPrescribed' => (float) ($row->total_prescribed ?? 0),
            ])
            ->all();

        return ['data' => $results];
    }
}
