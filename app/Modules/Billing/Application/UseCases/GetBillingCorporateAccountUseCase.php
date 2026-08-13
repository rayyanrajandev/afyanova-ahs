<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingCorporateAccountRepositoryInterface;

class GetBillingCorporateAccountUseCase
{
    public function __construct(private readonly BillingCorporateAccountRepositoryInterface $repository) {}

    public function execute(string $id): ?array
    {
        return $this->repository->findAccountById($id);
    }
}
