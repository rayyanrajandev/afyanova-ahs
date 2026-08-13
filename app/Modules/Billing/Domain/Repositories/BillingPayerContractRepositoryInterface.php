<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerContractRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByContractCode(
        string $contractCode,
        ?string $tenantId = null,
        ?string $facilityId = null,
        ?string $excludeId = null
    ): bool;

    public function search(
        ?string $query,
        ?string $payerType,
        ?string $status,
        ?string $currencyCode,
        ?bool $requiresPreAuthorization,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $payerType,
        ?string $currencyCode,
        ?bool $requiresPreAuthorization
    ): array;

    public function findActiveContractByProvider(
        string $payerName,
        string $tenantId,
        string $facilityId
    ): ?array;
}
