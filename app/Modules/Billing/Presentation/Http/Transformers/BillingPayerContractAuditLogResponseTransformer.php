<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class BillingPayerContractAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'billingPayerContractId' => $log['billing_payer_contract_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log);
    }
}
