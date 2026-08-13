<?php

namespace App\Modules\Platform\Domain\Repositories;

interface CrossTenantAdminAuditLogRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function write(
        string $action,
        string $operationType,
        ?int $actorId,
        ?string $targetTenantId,
        ?string $targetTenantCode,
        ?string $targetResourceType,
        ?string $targetResourceId,
        string $outcome,
        ?string $reason = null,
        array $metadata = [],
    ): void;

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array $filters, int $page, int $perPage): array;
}
