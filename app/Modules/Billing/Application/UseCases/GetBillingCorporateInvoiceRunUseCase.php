<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;

class GetBillingCorporateInvoiceRunUseCase
{
    public function __construct(private readonly BillingCorporateAccountRepositoryInterface $repository) {}

    public function execute(string $id): ?array
    {
        $run = $this->repository->findRunById($id);
        if ($run === null) {
            return null;
        }

        $run['invoices'] = $this->repository->runInvoices($id);
        $run['payments'] = $this->repository->runPayments($id);

        return $run;
    }
}
