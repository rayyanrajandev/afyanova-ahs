<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingCorporateAccountRepositoryInterface
{
    public function paginateAccountsForFacility(string $tenantId, string $facilityId, array $filters, int $page, int $perPage): array;

    public function findAccountById(string $id): ?array;

    public function createAccount(array $attributes): array;

    public function updateAccount(string $id, array $attributes): ?array;

    public function paginateRunsForAccount(string $accountId, array $filters, int $page, int $perPage): array;

    public function findRunById(string $id): ?array;

    public function createRun(array $attributes, array $runInvoices): array;

    public function updateRun(string $id, array $attributes): ?array;

    public function runInvoices(string $runId): array;

    public function updateRunInvoice(string $id, array $attributes): ?array;

    public function createRunPayment(array $attributes): array;

    public function runPayments(string $runId): array;

    public function eligibleInvoicesForRun(string $tenantId, string $facilityId, string $billingPayerContractId, string $fromDate, string $toDate): array;
}
