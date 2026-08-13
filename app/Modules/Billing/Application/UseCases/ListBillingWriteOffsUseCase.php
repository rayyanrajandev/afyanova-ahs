<?php

namespace App\Modules\Billing\Application\UseCases;

use App\Modules\Billing\Domain\Repositories\BillingWriteOffRepositoryInterface;

class ListBillingWriteOffsUseCase
{
    public function __construct(
        private readonly BillingWriteOffRepositoryInterface $repository,
    ) {}

    public function execute(array $filters): array
    {
        return $this->repository->search(
            query: $filters['query'] ?? null,
            invoiceId: $filters['invoiceId'] ?? null,
            patientId: $filters['patientId'] ?? null,
            status: $filters['status'] ?? null,
            page: (int) ($filters['page'] ?? 1),
            perPage: (int) ($filters['perPage'] ?? 15),
            sortBy: $filters['sortBy'] ?? null,
            sortDirection: $filters['sortDirection'] ?? 'desc',
        );
    }
}
