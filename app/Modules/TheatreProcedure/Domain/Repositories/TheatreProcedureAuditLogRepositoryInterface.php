<?php

namespace App\Modules\TheatreProcedure\Domain\Repositories;

interface TheatreProcedureAuditLogRepositoryInterface
{
    public function write(
        string $theatreProcedureId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByProcedureId(
        string $theatreProcedureId,
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
