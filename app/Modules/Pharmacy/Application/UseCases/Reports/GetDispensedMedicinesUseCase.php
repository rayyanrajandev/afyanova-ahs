<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\Pharmacy\Infrastructure\Models\PharmacyOrderModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetDispensedMedicinesUseCase
{
    public function __construct(
        private readonly PharmacyOrderModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $stockMovementBatchJoin = DB::raw("inventory_stock_movements.metadata->>'pharmacy_order_id'");

        $query = $this->model->newQuery()
            ->leftJoin('platform_clinical_catalog_items', 'pharmacy_orders.approved_medicine_catalog_item_id', '=', 'platform_clinical_catalog_items.id')
            ->leftJoin('inventory_dispensing_claim_links', 'pharmacy_orders.id', '=', 'inventory_dispensing_claim_links.pharmacy_order_id')
            ->leftJoin('inventory_batches', 'inventory_dispensing_claim_links.batch_id', '=', 'inventory_batches.id')
            ->leftJoin('inventory_stock_movements', function ($join) use ($stockMovementBatchJoin) {
                $join->on($stockMovementBatchJoin, '=', 'pharmacy_orders.id')
                     ->where('inventory_stock_movements.movement_type', 'issue');
            })
            ->leftJoin('users', 'users.id', '=', 'pharmacy_orders.ordered_by_user_id')
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
                $b->where('pharmacy_orders.order_number', 'like', $like)
                    ->orWhere('platform_clinical_catalog_items.name', 'like', $like)
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
                'users.name as dispensed_by_name',
                'pharmacy_orders.approved_medicine_catalog_item_id',
                'platform_clinical_catalog_items.code as item_code',
                'platform_clinical_catalog_items.name as medicine_name',
                'inventory_batches.batch_number',
                DB::raw('COALESCE(inventory_batches.internal_batch_number, inventory_stock_movements.internal_batch_number) as internal_batch_number'),
                'inventory_dispensing_claim_links.unit_cost',
                'inventory_dispensing_claim_links.total_cost',
            ])
            ->orderBy('pharmacy_orders.dispensed_at', 'desc')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($order) {
            return [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'patientId' => $order->patient_id,
                'patientName' => $order->patient_name ? trim($order->patient_name) : null,
                'medicineCode' => $order->item_code,
                'medicineName' => $order->medicine_name,
                'quantityDispensed' => (float) ($order->quantity_dispensed ?? 0),
                'unit' => $order->dispensed_unit,
                'dispensedAt' => $order->dispensed_at?->toIso8601String(),
                'dispensedByUserId' => $order->ordered_by_user_id,
                'dispensedByName' => $order->dispensed_by_name,
                'internalBatchNumber' => $order->internal_batch_number,
                'batchNumber' => $order->batch_number,
                'unitCost' => $order->unit_cost ? (float) $order->unit_cost : null,
                'totalCost' => $order->total_cost ? (float) $order->total_cost : null,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
        ];
    }
}
