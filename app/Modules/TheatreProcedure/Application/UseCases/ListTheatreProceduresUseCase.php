<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use App\Modules\TheatreProcedure\Domain\ValueObjects\TheatreProcedureStatus;
use Illuminate\Support\Str;

class ListTheatreProceduresUseCase
{
    public function __construct(private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, TheatreProcedureStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'procedureNumber' => 'procedure_number',
            'scheduledAt' => 'scheduled_at',
            'status' => 'status',
            'procedureType' => 'procedure_type',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'scheduledAt';
        $sortBy = $sortMap[$sortBy] ?? 'scheduled_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' || ! Str::isUuid($patientId) ? null : $patientId;

        $appointmentId = isset($filters['appointmentId']) ? trim((string) $filters['appointmentId']) : null;
        $appointmentId = $appointmentId === '' || ! Str::isUuid($appointmentId) ? null : $appointmentId;

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' || ! Str::isUuid($admissionId) ? null : $admissionId;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->theatreProcedureRepository->search(
            query: $query,
            patientId: $patientId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            status: $status,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
