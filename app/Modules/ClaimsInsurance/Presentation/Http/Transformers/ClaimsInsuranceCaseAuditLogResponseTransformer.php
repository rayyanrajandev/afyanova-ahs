<?php

namespace App\Modules\ClaimsInsurance\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class ClaimsInsuranceCaseAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'claimsInsuranceCaseId' => $log['claims_insurance_case_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'claims-insurance-case.document.pdf.downloaded' => 'PDF Downloaded',
        ]);
    }
}
