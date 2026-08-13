<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingInvoicePaymentRepositoryInterface
{
    public function create(array $attributes): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForBillingInvoice(string $billingInvoiceId, string $paymentId): ?array;

    public function sumAppliedReversalsForPayment(string $paymentId): float;

    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listByBillingInvoiceId(
        string $billingInvoiceId,
        int $page,
        int $perPage,
        array $filters = [],
    ): array;

    /**
     * @param  array<int, string>  $billingInvoiceIds
     * @return array<int, array<string, mixed>>
     */
    public function listByBillingInvoiceIds(array $billingInvoiceIds): array;
}
