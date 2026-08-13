<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerContractAuditLogRepositoryInterface
{
    public function write(
        string $billingPayerContractId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByBillingPayerContractId(
        string $billingPayerContractId,
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
