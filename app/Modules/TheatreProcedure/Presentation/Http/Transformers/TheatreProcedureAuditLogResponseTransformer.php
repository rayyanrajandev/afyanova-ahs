<?php

namespace App\Modules\TheatreProcedure\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class TheatreProcedureAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'action' => $log['action'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'changes' => $log['changes'] ?? null,
            'metadata' => $log['metadata'] ?? null,
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}
