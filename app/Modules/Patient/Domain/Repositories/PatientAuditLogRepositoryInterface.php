<?php

namespace App\Modules\Patient\Domain\Repositories;

interface PatientAuditLogRepositoryInterface
{
    public function write(
        string $patientId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByPatientId(
        string $patientId,
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
