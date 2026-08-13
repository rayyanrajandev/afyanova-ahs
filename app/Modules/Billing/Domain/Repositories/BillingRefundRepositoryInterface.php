<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingRefundRepositoryInterface
{
    /**
     * List refunds for a tenant/facility scope.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForFacility(string $tenantId, string $facilityId, array $filters = []): array;

    /**
     * Find a refund by ID
     */
    public function findById(string $id): ?array;

    /**
     * Find refunds by invoice ID
     */
    public function findByInvoiceId(string $invoiceId): array;

    /**
     * Find refunds by status
     */
    public function findByStatus(string $status): array;

    /**
     * Create a new refund
     */
    public function create(array $data): array;

    /**
     * Update a refund
     */
    public function update(string $id, array $data): array;
}
