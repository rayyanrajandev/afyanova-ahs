<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingDailyCloseRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function search(
        ?string $query,
        ?string $facilityId,
        ?string $status,
        ?string $fromDate,
        ?string $toDate,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function update(string $id, array $attributes): ?array;
}
