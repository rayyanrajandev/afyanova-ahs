<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventoryProcurementRequestResponseTransformer
{
    public static function transform(array $request): array
    {
        return [
            'id' => $request['id'] ?? null,
            'requestNumber' => $request['request_number'] ?? null,
            'purchaseOrderNumber' => $request['purchase_order_number'] ?? null,
            'itemId' => $request['item_id'] ?? null,
            'itemCode' => $request['item_code'] ?? null,
            'itemName' => $request['item_name'] ?? null,
            'itemCategory' => $request['item_category'] ?? null,
            'itemUnit' => $request['item_unit'] ?? null,
            'requestedQuantity' => $request['requested_quantity'] ?? null,
            'orderedQuantity' => $request['ordered_quantity'] ?? null,
            'receivedQuantity' => $request['received_quantity'] ?? null,
            'unitCostEstimate' => $request['unit_cost_estimate'] ?? null,
            'receivedUnitCost' => $request['received_unit_cost'] ?? null,
            'totalCostEstimate' => $request['total_cost_estimate'] ?? null,
            'requestedByUserId' => $request['requested_by_user_id'] ?? null,
            'approvedByUserId' => $request['approved_by_user_id'] ?? null,
            'status' => $request['status'] ?? null,
            'statusReason' => $request['status_reason'] ?? null,
            'neededBy' => $request['needed_by'] ?? null,
            'supplierId' => $request['supplier_id'] ?? null,
            'supplierName' => $request['supplier_name'] ?? null,
            'requestingDepartmentId' => $request['requesting_department_id'] ?? null,
            'approvalRoute' => $request['approval_route'] ?? null,
            'isCatalogItem' => $request['is_catalog_item'] ?? null,
            'sourceDepartmentRequisitionId' => $request['source_department_requisition_id'] ?? null,
            'sourceDepartmentRequisitionLineId' => $request['source_department_requisition_line_id'] ?? null,
            'sourceDepartmentRequisitionNumber' => $request['source_department_requisition_number'] ?? null,
            'sourceDepartmentName' => $request['source_department_name'] ?? null,
            'sourceDepartmentRequisitionStatus' => $request['source_department_requisition_status'] ?? null,
            'sourceLineRequestedQuantity' => $request['source_line_requested_quantity'] ?? null,
            'sourceLineApprovedQuantity' => $request['source_line_approved_quantity'] ?? null,
            'sourceLineIssuedQuantity' => $request['source_line_issued_quantity'] ?? null,
            'sourceLineUnit' => $request['source_line_unit'] ?? null,
            'receivingWarehouseId' => $request['receiving_warehouse_id'] ?? null,
            'receivingNotes' => $request['receiving_notes'] ?? null,
            'approvedAt' => $request['approved_at'] ?? null,
            'orderedAt' => $request['ordered_at'] ?? null,
            'receivedAt' => $request['received_at'] ?? null,
            'notes' => $request['notes'] ?? null,
            'createdAt' => $request['created_at'] ?? null,
            'updatedAt' => $request['updated_at'] ?? null,
        ];
    }
}
