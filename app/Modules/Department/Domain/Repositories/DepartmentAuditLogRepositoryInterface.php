<?php

namespace App\Modules\Department\Domain\Repositories;

interface DepartmentAuditLogRepositoryInterface
{
    public function write(
        string $departmentId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByDepartmentId(
        string $departmentId,
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

