<?php

namespace App\Modules\Staff\Domain\Repositories;

interface ClinicalPrivilegeCatalogAuditLogRepositoryInterface
{
    public function write(
        ?string $privilegeCatalogId,
        ?string $tenantId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void;

    public function listByPrivilegeCatalogId(
        string $privilegeCatalogId,
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
