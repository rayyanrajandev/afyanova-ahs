<?php

namespace App\Modules\InpatientWard\Domain\Repositories;

interface InpatientWardDischargeChecklistAuditLogRepositoryInterface
{
    public function write(
        string $inpatientWardDischargeChecklistId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByChecklistId(
        string $inpatientWardDischargeChecklistId,
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

