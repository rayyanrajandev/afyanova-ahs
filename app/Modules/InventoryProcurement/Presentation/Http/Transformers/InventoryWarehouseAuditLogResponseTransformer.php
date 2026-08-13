<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class InventoryWarehouseAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'inventoryWarehouseId' => $log['inventory_warehouse_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}

