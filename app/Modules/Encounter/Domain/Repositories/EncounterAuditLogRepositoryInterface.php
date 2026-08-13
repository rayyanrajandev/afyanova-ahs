<?php

namespace App\Modules\Encounter\Domain\Repositories;

interface EncounterAuditLogRepositoryInterface
{
    public function write(
        string $encounterId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void;

    public function listByEncounterId(
        string $encounterId,
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
