<?php

namespace App\Modules\Encounter\Application\UseCases;

use App\Modules\Encounter\Domain\Repositories\EncounterRepositoryInterface;
use App\Modules\Encounter\Domain\ValueObjects\EncounterStatus;
use Illuminate\Support\Str;

class ListEncountersUseCase
{
    public function __construct(private readonly EncounterRepositoryInterface $encounterRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, EncounterStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'encounterNumber' => 'encounter_number',
            'status' => 'status',
            'openedAt' => 'opened_at',
            'closedAt' => 'closed_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'openedAt';
        $sortBy = $sortMap[$sortBy] ?? 'opened_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

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

        return $this->encounterRepository->search(
            query: $query,
            patientId: $patientId,
            status: $status,
            primaryClinicianUserId: $primaryClinicianUserId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
