<?php

namespace App\Modules\Platform\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class PlatformRbacAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'tenantId' => $log['tenant_id'] ?? null,
            'facilityId' => $log['facility_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'targetType' => $log['target_type'] ?? null,
            'targetId' => $log['target_id'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}

