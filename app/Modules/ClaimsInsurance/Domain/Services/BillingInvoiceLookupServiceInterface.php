<?php

namespace App\Modules\ClaimsInsurance\Domain\Services;

interface BillingInvoiceLookupServiceInterface
{
    public function findInvoiceById(string $invoiceId): ?array;
}
