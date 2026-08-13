<?php

namespace App\Modules\EmergencyTriage\Domain\Repositories;

interface EmergencyTriageCaseAuditLogRepositoryInterface
{
    public function write(
        string $emergencyTriageCaseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByCaseId(
        string $emergencyTriageCaseId,
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
