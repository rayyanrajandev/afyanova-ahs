<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class CrossTenantAdminAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'action' => $log['action'] ?? null,
            'operationType' => $log['operation_type'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'targetTenantId' => $log['target_tenant_id'] ?? null,
            'targetTenantCode' => $log['target_tenant_code'] ?? null,
            'targetResourceType' => $log['target_resource_type'] ?? null,
            'targetResourceId' => $log['target_resource_id'] ?? null,
            'outcome' => $log['outcome'] ?? null,
            'reason' => $log['reason'] ?? null,
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}
