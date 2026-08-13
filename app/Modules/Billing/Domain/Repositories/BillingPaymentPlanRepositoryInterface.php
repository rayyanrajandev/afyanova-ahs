<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPaymentPlanRepositoryInterface
{
    public function paginateForFacility(string $tenantId, string $facilityId, array $filters, int $page, int $perPage): array;

    public function findById(string $id): ?array;

    public function findActiveBySource(?string $billingInvoiceId, ?string $cashBillingAccountId): ?array;

    public function create(array $attributes, array $installments): array;

    public function update(string $id, array $attributes): ?array;

    public function installments(string $billingPaymentPlanId): array;

    public function updateInstallment(string $id, array $attributes): ?array;
}
