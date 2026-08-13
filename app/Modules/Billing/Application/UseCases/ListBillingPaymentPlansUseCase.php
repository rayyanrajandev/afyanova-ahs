<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingPaymentPlanRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class ListBillingPaymentPlansUseCase
{
    public function __construct(
        private readonly BillingPaymentPlanRepositoryInterface $repository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        return $this->repository->paginateForFacility(
            tenantId: $this->platformScopeContext->tenantId(),
            facilityId: $this->platformScopeContext->facilityId(),
            filters: $filters,
            page: $page,
            perPage: $perPage,
        );
    }
}
