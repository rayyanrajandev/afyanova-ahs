<?php

namespace App\Modules\ClinicalProcedure\Domain\Repositories;

interface ClinicalProcedureOrderAuditLogRepositoryInterface
{
    public function write(
        string $clinicalProcedureOrderId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByClinicalProcedureOrderId(
        string $clinicalProcedureOrderId,
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
