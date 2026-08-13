<?php

namespace App\Modules\Billing\Presentation\Http\Transformers;

use App\Support\Audit\AuditLogPresenter;

class BillingInvoiceAuditLogResponseTransformer
{
    public static function transform(array $log): array
    {
        return AuditLogPresenter::enrich([
            'id' => $log['id'] ?? null,
            'billingInvoiceId' => $log['billing_invoice_id'] ?? null,
            'actorId' => $log['actor_id'] ?? null,
            'action' => $log['action'] ?? null,
            'changes' => $log['changes'] ?? [],
            'metadata' => $log['metadata'] ?? [],
            'createdAt' => $log['created_at'] ?? null,
        ], $log, [
            'billing-invoice.document.pdf.downloaded' => 'PDF Downloaded',
        ]);
    }
}
