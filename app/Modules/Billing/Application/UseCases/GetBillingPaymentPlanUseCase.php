<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPaymentPlanRepositoryInterface;

class GetBillingPaymentPlanUseCase
{
    public function __construct(private readonly BillingPaymentPlanRepositoryInterface $repository) {}

    public function execute(string $id): ?array
    {
        $plan = $this->repository->findById($id);
        if ($plan === null) {
            return null;
        }

        $plan['installments'] = $this->repository->installments($id);

        return $plan;
    }
}
