<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetMedicinesByClinicianUseCase
{
    public function __construct(
        private readonly PharmacyOrderModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $query = $this->model->newQuery()
            ->leftJoin('users', 'users.id', '=', 'pharmacy_orders.ordered_by_user_id')
            ->whereIn('pharmacy_orders.status', ['dispensed', 'partially_dispensed']);

        $this->helper->applyPlatformScopeIfEnabled($query);

        if ($from = ($filters['from'] ?? null)) {
            $query->where('pharmacy_orders.dispensed_at', '>=', Carbon::parse($from));
        }
        if ($to = ($filters['to'] ?? null)) {
            $query->where('pharmacy_orders.dispensed_at', '<=', Carbon::parse($to)->endOfDay());
        }

        $results = $query
            ->select(
                'pharmacy_orders.ordered_by_user_id',
                'users.name as clinician_name',
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(pharmacy_orders.quantity_dispensed), 0) as total_quantity'),
                DB::raw('COUNT(DISTINCT pharmacy_orders.patient_id) as patient_count'),
            )
            ->groupBy('pharmacy_orders.ordered_by_user_id', 'users.name')
            ->orderByDesc('order_count')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($row) {
            return [
                'userId' => $row->ordered_by_user_id,
                'clinicianName' => $row->clinician_name,
                'orderCount' => (int) $row->order_count,
                'totalQuantity' => (float) ($row->total_quantity ?? 0),
                'patientCount' => (int) ($row->patient_count ?? 0),
            ];
        }, $results->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($results),
        ];
    }
}
