<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;

class ListBillingCorporateInvoiceRunsUseCase
{
    public function __construct(private readonly BillingCorporateAccountRepositoryInterface $repository) {}

    public function execute(string $accountId, array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 10), 1), 100);

        return $this->repository->paginateRunsForAccount($accountId, $filters, $page, $perPage);
    }
}
