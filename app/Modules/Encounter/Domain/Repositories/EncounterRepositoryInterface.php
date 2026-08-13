<?php

namespace App\Modules\Encounter\Domain\Repositories;

interface EncounterRepositoryInterface
{
    /**
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function search(
        ?string $query,
        ?string $patientId,
        ?string $status,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime,
        int $page,
        int $perPage,
        ?string $sortBy,
        string $sortDirection
    ): array;

    /**
     * @return array<string, int>
     */
    public function statusCounts(
        ?string $query,
        ?string $patientId,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime
    ): array;
}
