<?php

namespace App\Modules\Admission\Domain\Repositories;

interface AdmissionAuditLogRepositoryInterface
{
    public function write(
        string $admissionId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByAdmissionId(
        string $admissionId,
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
