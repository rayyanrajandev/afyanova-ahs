<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventoryWarehouseResponseTransformer
{
    public static function transform(array $warehouse): array
    {
        return [
            'id' => $warehouse['id'] ?? null,
            'warehouseCode' => $warehouse['warehouse_code'] ?? null,
            'warehouseName' => $warehouse['warehouse_name'] ?? null,
            'warehouseType' => $warehouse['warehouse_type'] ?? null,
            'location' => $warehouse['location'] ?? null,
            'contactPerson' => $warehouse['contact_person'] ?? null,
            'phone' => $warehouse['phone'] ?? null,
            'email' => $warehouse['email'] ?? null,
            'status' => $warehouse['status'] ?? null,
            'statusReason' => $warehouse['status_reason'] ?? null,
            'notes' => $warehouse['notes'] ?? null,
            'createdAt' => $warehouse['created_at'] ?? null,
            'updatedAt' => $warehouse['updated_at'] ?? null,
        ];
    }
}

