<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use Illuminate\Support\Str;

class ListEmergencyTriageCaseStatusCountsUseCase
{
    public function __construct(private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $allowedTriageLevels = ['red', 'yellow', 'green'];
        $triageLevel = isset($filters['triageLevel']) ? strtolower(trim((string) $filters['triageLevel'])) : null;
        if (! in_array($triageLevel, $allowedTriageLevels, true)) {
            $triageLevel = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->emergencyTriageCaseRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            triageLevel: $triageLevel,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
