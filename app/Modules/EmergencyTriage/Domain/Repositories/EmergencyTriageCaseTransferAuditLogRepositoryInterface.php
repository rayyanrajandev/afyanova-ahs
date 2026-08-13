<?php

namespace App\Modules\EmergencyTriage\Domain\Repositories;

interface EmergencyTriageCaseTransferAuditLogRepositoryInterface
{
    public function write(
        string $transferId,
        string $emergencyTriageCaseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByTransferId(
        string $transferId,
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
