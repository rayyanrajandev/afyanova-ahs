<?php

namespace App\Modules\Billing\Domain\Repositories;

interface BillingPayerAuthorizationRuleAuditLogRepositoryInterface
{
    public function write(
        string $billingPayerAuthorizationRuleId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByBillingPayerAuthorizationRuleId(
        string $billingPayerAuthorizationRuleId,
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
