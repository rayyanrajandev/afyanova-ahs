<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerContractPriceOverrideAuditLogRepositoryInterface
{
    public function write(
        string $billingPayerContractPriceOverrideId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByBillingPayerContractPriceOverrideId(
        string $billingPayerContractPriceOverrideId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime,
    ): array;
}
