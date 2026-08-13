<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;
use App\Modules\EmergencyTriage\Domain\ValueObjects\EmergencyTriageCaseStatus;

class ListEmergencyTriageCasesUseCase
{
    public function __construct(private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, EmergencyTriageCaseStatus::values(), true)) {
            $status = null;
        }

        $allowedTriageLevels = ['red', 'yellow', 'green'];
        $triageLevel = isset($filters['triageLevel']) ? strtolower(trim((string) $filters['triageLevel'])) : null;
        if (! in_array($triageLevel, $allowedTriageLevels, true)) {
            $triageLevel = null;
        }

        $sortMap = [
            'caseNumber' => 'case_number',
            'arrivalAt' => 'arrived_at',
            'triagedAt' => 'triaged_at',
            'status' => 'status',
            'triageLevel' => 'triage_level',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'arrivalAt';
        $sortBy = $sortMap[$sortBy] ?? 'arrived_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->emergencyTriageCaseRepository->search(
            query: $query,
            patientId: $patientId,
            status: $status,
            triageLevel: $triageLevel,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
