<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Domain\Repositories\EncounterRepositoryInterface;
use Illuminate\Support\Str;

class ListEncounterStatusCountsUseCase
{
    public function __construct(private readonly EncounterRepositoryInterface $encounterRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $primaryClinicianUserId = isset($filters['primaryClinicianUserId']) ? (int) $filters['primaryClinicianUserId'] : null;
        if ($primaryClinicianUserId !== null && $primaryClinicianUserId <= 0) {
            $primaryClinicianUserId = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->encounterRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            primaryClinicianUserId: $primaryClinicianUserId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
