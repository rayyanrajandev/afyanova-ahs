<?php

namespace App\Modules\Staff\Domain\Repositories;

interface ClinicalSpecialtyAuditLogRepositoryInterface
{
    public function write(
        ?string $specialtyId,
        ?string $tenantId,
        ?string $staffProfileId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void;

    public function listBySpecialtyId(
        string $specialtyId,
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

