<?php

namespace App\Modules\Staff\Domain\Repositories;

interface StaffCredentialingAuditLogRepositoryInterface
{
    public function write(
        string $staffProfileId,
        ?string $tenantId,
        ?string $staffRegulatoryProfileId,
        ?string $staffProfessionalRegistrationId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = [],
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
