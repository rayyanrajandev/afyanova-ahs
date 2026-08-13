<?php

namespace App\Modules\ClinicalProcedure\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class ClinicalProcedureOrderAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'clinicalProcedureOrderId' => $log['clinical_procedure_order_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}
