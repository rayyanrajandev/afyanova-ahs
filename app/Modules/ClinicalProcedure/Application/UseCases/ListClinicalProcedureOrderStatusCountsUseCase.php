<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use Illuminate\Support\Str;

class ListClinicalProcedureOrderStatusCountsUseCase
{
    public function __construct(private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $appointmentId = isset($filters['appointmentId']) ? trim((string) $filters['appointmentId']) : null;
        $appointmentId = $appointmentId === '' ? null : $appointmentId;
        if ($appointmentId !== null && ! Str::isUuid($appointmentId)) {
            $appointmentId = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' ? null : $admissionId;
        if ($admissionId !== null && ! Str::isUuid($admissionId)) {
            $admissionId = null;
        }

        $allowedProcedureSettings = ['outpatient', 'inpatient', 'bedside', 'emergency', 'other'];
        $procedureSetting = isset($filters['procedureSetting']) ? strtolower(trim((string) $filters['procedureSetting'])) : null;
        if (! in_array($procedureSetting, $allowedProcedureSettings, true)) {
            $procedureSetting = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->clinicalProcedureOrderRepository->statusCounts(
            query: $query,
            patientId: $patientId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            procedureSetting: $procedureSetting,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
        );
    }
}
