<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingWriteOffRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function search(
        ?string $query,
        ?string $invoiceId,
        ?string $patientId,
        ?string $status,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function update(string $id, array $attributes): ?array;
}
