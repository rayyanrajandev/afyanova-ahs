<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPayerContractRepositoryInterface;

class GetBillingPayerContractUseCase
{
    public function __construct(private readonly BillingPayerContractRepositoryInterface $repository) {}

    public function execute(string $id): ?array
    {
        return $this->repository->findById($id);
    }
}
