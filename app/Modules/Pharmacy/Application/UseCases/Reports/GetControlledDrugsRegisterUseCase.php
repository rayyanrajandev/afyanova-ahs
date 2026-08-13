<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetControlledDrugsRegisterUseCase
{
    public function __construct(
        private readonly PharmacyOrderModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $stockMovementBatchJoin = DB::raw("inventory_stock_movements.metadata->>'pharmacy_order_id'");

        $query = $this->model->newQuery()
            ->join('platform_clinical_catalog_items', function ($join) {
                $join->on('pharmacy_orders.approved_medicine_catalog_item_id', '=', 'platform_clinical_catalog_items.id')
                    ->where('platform_clinical_catalog_items.is_controlled_substance', true);
            })
            ->leftJoin('inventory_dispensing_claim_links', 'pharmacy_orders.id', '=', 'inventory_dispensing_claim_links.pharmacy_order_id')
            ->leftJoin('inventory_batches', 'inventory_dispensing_claim_links.batch_id', '=', 'inventory_batches.id')
            ->leftJoin('inventory_stock_movements', function ($join) use ($stockMovementBatchJoin) {
                $join->on($stockMovementBatchJoin, '=', 'pharmacy_orders.id')
                     ->where('inventory_stock_movements.movement_type', 'issue');
            })
            ->leftJoin('users as prescriber', 'prescriber.id', '=', 'pharmacy_orders.ordered_by_user_id')
            ->leftJoin('users as verifier', 'verifier.id', '=', 'pharmacy_orders.verified_by_user_id')
            ->leftJoin('patients', 'patients.id', '=', 'pharmacy_orders.patient_id')
            ->whereIn('pharmacy_orders.status', ['dispensed', 'partially_dispensed']);

        $this->helper->applyPlatformScopeIfEnabled($query);

        if ($from = ($filters['from'] ?? null)) {
            $query->where('pharmacy_orders.dispensed_at', '>=', Carbon::parse($from));
        }
        if ($to = ($filters['to'] ?? null)) {
            $query->where('pharmacy_orders.dispensed_at', '<=', Carbon::parse($to)->endOfDay());
        }
        if ($patientId = ($filters['patientId'] ?? null)) {
            $query->where('pharmacy_orders.patient_id', $patientId);
        }
        if ($q = ($filters['q'] ?? null)) {
            $like = '%'.$q.'%';
            $query->where(function ($b) use ($like) {
                $b->where('platform_clinical_catalog_items.name', 'like', $like)
                    ->orWhere('pharmacy_orders.patient_id', 'like', $like);
            });
        }

        $paginator = $query
            ->select([
                'pharmacy_orders.id',
                'pharmacy_orders.order_number',
                'pharmacy_orders.patient_id',
                \Illuminate\Support\Facades\DB::raw("CONCAT_WS(' ', patients.first_name, patients.middle_name, patients.last_name) as patient_name"),
                'pharmacy_orders.quantity_dispensed',
                'pharmacy_orders.dispensed_unit',
                'pharmacy_orders.dispensed_at',
                'pharmacy_orders.ordered_by_user_id',
                'pharmacy_orders.verified_by_user_id',
                'prescriber.name as prescriber_name',
                'verifier.name as verifier_name',
                'pharmacy_orders.approved_medicine_catalog_item_id',
                'platform_clinical_catalog_items.name as medicine_name',
                'platform_clinical_catalog_items.strength',
                'platform_clinical_catalog_items.dosage_form',
                'platform_clinical_catalog_items.controlled_substance_schedule',
                'inventory_batches.batch_number',
                DB::raw('COALESCE(inventory_batches.internal_batch_number, inventory_stock_movements.internal_batch_number) as internal_batch_number'),
            ])
            ->orderBy('pharmacy_orders.dispensed_at', 'desc')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($row) {
            return [
                'id' => $row->id,
                'orderNumber' => $row->order_number,
                'dispensedAt' => $row->dispensed_at?->toIso8601String(),
                'patientId' => $row->patient_id,
                'patientName' => $row->patient_name ? trim($row->patient_name) : null,
                'medicineName' => $row->medicine_name,
                'strength' => $row->strength,
                'dosageForm' => $row->dosage_form,
                'schedule' => $row->controlled_substance_schedule,
                'internalBatchNumber' => $row->internal_batch_number,
                'batchNumber' => $row->batch_number,
                'quantityDispensed' => (float) ($row->quantity_dispensed ?? 0),
                'unit' => $row->dispensed_unit,
                'prescriberUserId' => $row->ordered_by_user_id,
                'prescriberName' => $row->prescriber_name,
                'verifierUserId' => $row->verified_by_user_id,
                'verifierName' => $row->verifier_name,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }
}
