<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventoryDepartmentRequisitionResponseTransformer
{
    public static function transform(array $requisition): array
    {
        return [
            'id' => $requisition['id'] ?? null,
            'requisitionNumber' => $requisition['requisition_number'] ?? null,
            'requestingDepartment' => $requisition['requesting_department'] ?? null,
            'requestingDepartmentId' => $requisition['requesting_department_id'] ?? null,
            'issuingStore' => $requisition['issuing_store'] ?? null,
            'issuingWarehouseId' => $requisition['issuing_warehouse_id'] ?? null,
            'priority' => $requisition['priority'] ?? null,
            'status' => $requisition['status'] ?? null,
            'requestedByUserId' => $requisition['requested_by_user_id'] ?? null,
            'approvedByUserId' => $requisition['approved_by_user_id'] ?? null,
            'issuedByUserId' => $requisition['issued_by_user_id'] ?? null,
            'approvedAt' => $requisition['approved_at'] ?? null,
            'issuedAt' => $requisition['issued_at'] ?? null,
            'neededBy' => $requisition['needed_by'] ?? null,
            'notes' => $requisition['notes'] ?? null,
            'rejectionReason' => $requisition['rejection_reason'] ?? null,
            'lines' => isset($requisition['lines']) && is_array($requisition['lines'])
                ? array_map([self::class, 'transformLine'], $requisition['lines'])
                : null,
            'createdAt' => $requisition['created_at'] ?? null,
            'updatedAt' => $requisition['updated_at'] ?? null,
        ];
    }

    public static function transformLine(array $line): array
    {
        return [
            'id' => $line['id'] ?? null,
            'requisitionId' => $line['requisition_id'] ?? null,
            'itemId' => $line['item_id'] ?? null,
            'batchId' => $line['batch_id'] ?? null,
            'itemCode' => $line['item_code'] ?? null,
            'itemName' => $line['item_name'] ?? null,
            'itemCategory' => $line['item_category'] ?? null,
            'itemSubcategory' => $line['item_subcategory'] ?? null,
            'itemCurrentStock' => $line['item_current_stock'] ?? null,
            'requestedQuantity' => $line['requested_quantity'] ?? null,
            'approvedQuantity' => $line['approved_quantity'] ?? null,
            'issuedQuantity' => $line['issued_quantity'] ?? null,
            'unit' => $line['unit'] ?? null,
            'notes' => $line['notes'] ?? null,
        ];
    }
}
