<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffProfileAuditLogRepositoryInterface
{
    public function write(
        string $staffProfileId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByStaffProfileId(
        string $staffProfileId,
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
