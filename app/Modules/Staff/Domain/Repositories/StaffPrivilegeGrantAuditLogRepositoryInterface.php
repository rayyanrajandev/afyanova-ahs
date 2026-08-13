<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffPrivilegeGrantAuditLogRepositoryInterface
{
    public function write(
        string $staffPrivilegeGrantId,
        string $staffProfileId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
    ): void;

    public function listByStaffPrivilegeGrantId(
        string $staffPrivilegeGrantId,
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

