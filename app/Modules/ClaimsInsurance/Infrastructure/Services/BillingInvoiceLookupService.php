<?php

namespace App\Modules\ClaimsInsurance\Infrastructure\Services;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;
use App\Modules\ClaimsInsurance\Domain\Services\BillingInvoiceLookupServiceInterface;

class BillingInvoiceLookupService implements BillingInvoiceLookupServiceInterface
{
    public function __construct(private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository) {}

    public function findInvoiceById(string $invoiceId): ?array
    {
        return $this->billingInvoiceRepository->findById($invoiceId);
    }
}
