<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\CashBillingAccountRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class ListCashBillingAccountsUseCase
{
    public function __construct(
        private readonly CashBillingAccountRepositoryInterface $cashBillingAccountRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function execute(array $filters = []): array
    {
        $tenantId = $this->platformScopeContext->tenantId();
        $facilityId = $this->platformScopeContext->facilityId();

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        return $this->cashBillingAccountRepository->paginateForFacility(
            tenantId: $tenantId,
            facilityId: $facilityId,
            filters: $filters,
            page: $page,
            perPage: $perPage,
        );
    }
}
