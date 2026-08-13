<?php

namespace App\Modules\Pharmacy\Application\UseCases\Reports;

use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDispensingClaimLinkModel;
use App\Modules\Pharmacy\Application\Support\Reports\ReportQueryHelper;

class GetInsuranceClaimsUseCase
{
    public function __construct(
        private readonly InventoryDispensingClaimLinkModel $model,
        private readonly ReportQueryHelper $helper,
    ) {}

    public function execute(array $filters): array
    {
        $query = $this->model->newQuery()
            ->leftJoin('inventory_items', 'inventory_dispensing_claim_links.item_id', '=', 'inventory_items.id')
            ->leftJoin('patients', 'patients.id', '=', 'inventory_dispensing_claim_links.patient_id');

        $this->helper->applyPlatformScopeIfEnabled($query);
        $this->helper->applyDispensingFilters($query, $filters);

        $summaryQuery = clone $query;

        $summary = [
            'totalClaims' => (clone $summaryQuery)->count(),
            'pendingClaims' => (clone $summaryQuery)->where('claim_status', 'pending')->count(),
            'submittedClaims' => (clone $summaryQuery)->where('claim_status', 'submitted')->count(),
            'approvedClaims' => (clone $summaryQuery)->where('claim_status', 'approved')->count(),
            'rejectedClaims' => (clone $summaryQuery)->where('claim_status', 'rejected')->count(),
            'totalApprovedAmount' => (float) ((clone $summaryQuery)->whereIn('claim_status', ['approved', 'partially_approved'])->sum('approved_amount') ?? 0),
            'totalRejectedAmount' => (float) ((clone $summaryQuery)->where('claim_status', 'rejected')->sum('rejected_amount') ?? 0),
        ];

        if (($filters['view'] ?? null) === 'summary') {
            return ['summary' => $summary];
        }

        $paginator = $query
            ->select([
                'inventory_dispensing_claim_links.*',
                'inventory_items.item_name',
                \Illuminate\Support\Facades\DB::raw("CONCAT_WS(' ', patients.first_name, patients.middle_name, patients.last_name) as patient_name"),
            ])
            ->orderBy('inventory_dispensing_claim_links.created_at', 'desc')
            ->paginate(perPage: (int) ($filters['perPage'] ?? 50), page: (int) ($filters['page'] ?? 1));

        $data = array_map(function ($link) {
            return [
                'id' => $link->id,
                'pharmacyOrderId' => $link->pharmacy_order_id,
                'itemName' => $link->item_name,
                'patientId' => $link->patient_id,
                'patientName' => $link->patient_name ? trim($link->patient_name) : null,
                'payerName' => $link->payer_name,
                'payerType' => $link->payer_type,
                'claimStatus' => $link->claim_status,
                'quantityDispensed' => (float) $link->quantity_dispensed,
                'totalCost' => $link->total_cost ? (float) $link->total_cost : null,
                'approvedAmount' => $link->approved_amount ? (float) $link->approved_amount : null,
                'rejectedAmount' => $link->rejected_amount ? (float) $link->rejected_amount : null,
                'submittedAt' => $link->submitted_at?->toIso8601String(),
                'adjudicatedAt' => $link->adjudicated_at?->toIso8601String(),
                'nhifCode' => $link->nhif_code,
            ];
        }, $paginator->items());

        return [
            'data' => $data,
            'meta' => $this->helper->paginatorMeta($paginator),
            'summary' => $summary,
        ];
    }
}
