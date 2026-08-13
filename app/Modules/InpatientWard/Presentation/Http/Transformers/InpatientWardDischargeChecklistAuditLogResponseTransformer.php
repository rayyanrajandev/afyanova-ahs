<?php

namespace App\Modules\InpatientWard\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class InpatientWardDischargeChecklistAuditLogResponseTransformer
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
        ], $log, [
            'inpatient-ward-discharge-checklist.document.pdf.downloaded' => 'PDF Downloaded',
        ]);
    }
}
