<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;
use Illuminate\Support\Str;

class ListTheatreProcedureStatusCountsUseCase
{
    public function __construct(private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository) {}

    public function execute(array $filters): array
    {
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

        return $this->theatreProcedureRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
