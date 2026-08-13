<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingInvoiceAuditLogRepositoryInterface
{
    public function write(
        string $billingInvoiceId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByBillingInvoiceId(
        string $billingInvoiceId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array;

    /**
     * @param  array<int, string>  $billingInvoiceIds
     * @return array<int, array<string, mixed>>
     */
    public function listByBillingInvoiceIds(array $billingInvoiceIds): array;
}
