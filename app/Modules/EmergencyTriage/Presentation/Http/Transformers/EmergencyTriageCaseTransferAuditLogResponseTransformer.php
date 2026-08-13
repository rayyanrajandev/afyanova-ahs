<?php

namespace App\Modules\EmergencyTriage\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class EmergencyTriageCaseTransferAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'emergencyTriageCaseTransferId' => $log['emergency_triage_case_transfer_id'] ?? null,
            'emergencyTriageCaseId' => $log['emergency_triage_case_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}
