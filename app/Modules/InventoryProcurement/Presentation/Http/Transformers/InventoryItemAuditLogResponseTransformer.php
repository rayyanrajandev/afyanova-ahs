<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Transformers;

class InventoryItemAuditLogResponseTransformer
{
    public static function transform(array $auditLog): array
    {
        return [
            'id' => $auditLog['id'] ?? null,
            'action' => $auditLog['action'] ?? null,
            'actorId' => $auditLog['actor_id'] ?? null,
            'changes' => $auditLog['changes'] ?? [],
            'metadata' => $auditLog['metadata'] ?? [],
            'createdAt' => $auditLog['created_at'] ?? null,
        ];
    }
}

