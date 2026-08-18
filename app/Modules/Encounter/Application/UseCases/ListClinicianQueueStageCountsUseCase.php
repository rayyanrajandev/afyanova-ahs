<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Domain\Repositories\EncounterRepositoryInterface;
use Illuminate\Support\Str;

/**
 * How many visits sit in each pile of the clinician queue.
 *
 * Separate from the list for the same reason reception's is: a page of one pile
 * cannot know the size of the others. The browser used to total the rows it had
 * been given, so the tab badges described the first page rather than the queue.
 */
class ListClinicianQueueStageCountsUseCase
{
    public function __construct(private readonly EncounterRepositoryInterface $encounterRepository) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' || ($patientId !== null && ! Str::isUuid($patientId)) ? null : $patientId;

        $primaryClinicianUserId = isset($filters['primaryClinicianUserId']) ? (int) $filters['primaryClinicianUserId'] : null;
        $primaryClinicianUserId = $primaryClinicianUserId !== null && $primaryClinicianUserId > 0 ? $primaryClinicianUserId : null;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->encounterRepository->queueStageCounts(
            query: $query,
            patientId: $patientId,
            primaryClinicianUserId: $primaryClinicianUserId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
