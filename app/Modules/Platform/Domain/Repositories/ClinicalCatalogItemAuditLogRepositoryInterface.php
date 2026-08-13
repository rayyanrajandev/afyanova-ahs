<?php

namespace App\Modules\Platform\Domain\Repositories;

interface ClinicalCatalogItemAuditLogRepositoryInterface
{
    public function write(
        string $clinicalCatalogItemId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByClinicalCatalogItemId(
        string $clinicalCatalogItemId,
        int $page,
        int $perPage,
        ?string $query,
        ?string $action,
        ?string $actorType,
        ?int $actorId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
