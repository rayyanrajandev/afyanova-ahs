<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingDiscountRepositoryInterface
{
    /**
     * Find a discount by ID
     */
    public function findById(string $id): ?array;

    /**
     * Find discount applied to an invoice
     */
    public function findByInvoiceId(string $invoiceId): ?array;

    /**
     * Create a new discount record
     */
    public function create(array $data): array;
}
