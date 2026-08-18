<?php

namespace App\Modules\Encounter\Domain\Repositories;

use App\Modules\Encounter\Domain\ValueObjects\ClinicianQueueStage;

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
        string $sortDirection,
        ?ClinicianQueueStage $queueStage = null
    ): array;

    /**
     * How many visits sit in each clinician queue pile.
     *
     * Counted across the whole result set, not the page being displayed — the
     * browser used to total whatever the first page happened to contain, so the
     * tab badges agreed with a truncated list rather than with reality.
     *
     * @return array<string, int>
     */
    public function queueStageCounts(
        ?string $query,
        ?string $patientId,
        ?int $primaryClinicianUserId,
        ?string $fromDateTime,
        ?string $toDateTime
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
