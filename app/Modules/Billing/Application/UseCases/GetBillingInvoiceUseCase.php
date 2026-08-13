<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingInvoiceRepositoryInterface;

class GetBillingInvoiceUseCase
{
    public function __construct(private readonly BillingInvoiceRepositoryInterface $billingInvoiceRepository) {}

    public function execute(string $id): ?array
    {
        return $this->billingInvoiceRepository->findById($id);
    }
}
