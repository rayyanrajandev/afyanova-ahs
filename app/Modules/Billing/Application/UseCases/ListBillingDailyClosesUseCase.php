<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingDailyCloseRepositoryInterface;

class ListBillingDailyClosesUseCase
{
    public function __construct(
        private readonly BillingDailyCloseRepositoryInterface $repository,
    ) {}

    public function execute(array $filters): array
    {
        return $this->repository->search(
            query: $filters['query'] ?? null,
            facilityId: $filters['facilityId'] ?? null,
            status: $filters['status'] ?? null,
            fromDate: $filters['fromDate'] ?? null,
            toDate: $filters['toDate'] ?? null,
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['perPage'] ?? 15),
            sortBy: $filters['sortBy'] ?? null,
            sortDirection: $filters['sortDirection'] ?? 'desc',
        );
    }
}
