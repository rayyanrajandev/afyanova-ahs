<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffDocumentAuditLogRepositoryInterface
{
    public function write(
        string $staffDocumentId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByStaffDocumentId(
        string $staffDocumentId,
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

