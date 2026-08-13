<?php

namespace App\Modules\EmergencyTriage\Domain\Repositories;

interface EmergencyTriageCaseTransferRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function findByCaseAndId(string $emergencyTriageCaseId, string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function existsByTransferNumber(string $transferNumber): bool;

    public function searchByCase(
        string $emergencyTriageCaseId,
        ?string $query,
        ?string $transferType,
        ?string $priority,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCountsByCase(
        string $emergencyTriageCaseId,
        ?string $query,
        ?string $transferType,
        ?string $priority,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
