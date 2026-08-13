<?php

namespace App\Modules\Billing\Application\Listeners;

use App\Modules\Billing\Domain\Events\InvoicePaymentRecorded;
use App\Modules\Billing\Domain\Events\InvoicePaymentReversed;
use App\Modules\Billing\Domain\Events\InvoiceStatusChanged;
use App\Modules\Billing\Infrastructure\Models\BillingSourceStatusModel;

/**
 * billing-financial-state-remediation-plan.md, Phase 2. Keeps billing_source_status
 * current -- the seed of a future shared "is this billed" resolver (Phase 3).
 * Registered for all three invoice-mutation events in BillingServiceProvider;
 * each handler method just forwards to the same upsert logic, since the
 * projection only cares about "this invoice's sources now have this status."
 */
class SyncBillingSourceStatusProjection
{
    public function handleInvoiceStatusChanged(InvoiceStatusChanged $event): void
    {
        $this->sync($event->billingInvoiceId, $event->status, $event->sources);
    }

    public function handleInvoicePaymentRecorded(InvoicePaymentRecorded $event): void
    {
        $this->sync($event->billingInvoiceId, $event->status, $event->sources);
    }

    public function handleInvoicePaymentReversed(InvoicePaymentReversed $event): void
    {
        $this->sync($event->billingInvoiceId, $event->status, $event->sources);
    }

    /**
     * @param  array<int, array{kind: string, id: string}>  $sources
     */
    private function sync(string $billingInvoiceId, string $status, array $sources): void
    {
        foreach ($sources as $source) {
            BillingSourceStatusModel::query()->updateOrCreate(
                [
                    'source_workflow_kind' => $source['kind'],
                    'source_workflow_id' => $source['id'],
                ],
                [
                    'status' => $status,
                    'billing_invoice_id' => $billingInvoiceId,
                ],
            );
        }
    }
}
