<?php

namespace App\Modules\TheatreProcedure\Domain\Repositories;

interface TheatreProcedureRepositoryInterface
{
    public function create(array $attributes): array;

    public function findById(string $id): ?array;

    public function update(string $id, array $attributes): ?array;

    public function delete(string $id): bool;

    public function existsByProcedureNumber(string $procedureNumber): bool;

    public function search(
        ?string $query,
        ?string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $status,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?string $appointmentId,
        ?string $admissionId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
