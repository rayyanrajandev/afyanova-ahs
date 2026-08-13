<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoicePaymentRepositoryInterface;

class GetBillingInvoicePaymentUseCase
{
    public function __construct(private readonly BillingInvoicePaymentRepositoryInterface $billingInvoicePaymentRepository) {}

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $billingInvoiceId, string $paymentId): ?array
    {
        return $this->billingInvoicePaymentRepository->findByIdForBillingInvoice($billingInvoiceId, $paymentId);
    }
}
