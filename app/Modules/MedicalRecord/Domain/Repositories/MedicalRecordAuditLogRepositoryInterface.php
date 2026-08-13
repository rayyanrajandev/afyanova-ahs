<?php

namespace App\Modules\MedicalRecord\Domain\Repositories;

interface MedicalRecordAuditLogRepositoryInterface
{
    public function write(
        string $medicalRecordId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByMedicalRecordId(
        string $medicalRecordId,
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
