<?php

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * billing-financial-state-remediation-plan.md, Phase 2. Plain server-side domain
 * event fired by RecordBillingInvoicePaymentUseCase after a payment is recorded.
 * See InvoiceStatusChanged for the shared listener/purpose.
 */
class InvoicePaymentRecorded
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
