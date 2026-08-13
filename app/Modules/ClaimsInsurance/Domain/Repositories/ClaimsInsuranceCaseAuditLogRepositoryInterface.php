<?php

namespace App\Modules\ClaimsInsurance\Domain\Repositories;

interface ClaimsInsuranceCaseAuditLogRepositoryInterface
{
    public function write(
        string $claimsInsuranceCaseId,
        string $action,
        ?int $actorId,
        array $changes = [],
        array $metadata = []
    ): void;

    public function listByCaseId(
        string $claimsInsuranceCaseId,
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
