<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * billing-financial-state-remediation-plan.md, Phase 2. Plain server-side domain
 * event (not ShouldBroadcast -- this is for reconciliation, not a UI push) fired
 * whenever UpdateBillingInvoiceStatusUseCase changes an invoice's status.
 * SyncBillingSourceStatusProjection listens for this (and InvoicePaymentRecorded/
 * InvoicePaymentReversed) to keep billing_source_status current.
 */
class InvoiceStatusChanged
{
    use Dispatchable;

    /**
     * @param  array<int, array{kind: string, id: string}>  $sources
     */
    public function __construct(
        public readonly string $billingInvoiceId,
        public readonly ?string $patientId,
        public readonly string $status,
        public readonly array $sources,
    ) {}
}
