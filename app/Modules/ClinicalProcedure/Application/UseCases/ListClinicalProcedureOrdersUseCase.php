<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;
use App\Modules\ClinicalProcedure\Domain\ValueObjects\ClinicalProcedureOrderStatus;
use Illuminate\Support\Str;

class ListClinicalProcedureOrdersUseCase
{
    public function __construct(private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, ClinicalProcedureOrderStatus::values(), true)) {
            $status = null;
        }

        $allowedProcedureSettings = ['outpatient', 'inpatient', 'bedside', 'emergency', 'other'];
        $procedureSetting = isset($filters['procedureSetting']) ? strtolower(trim((string) $filters['procedureSetting'])) : null;
        if (! in_array($procedureSetting, $allowedProcedureSettings, true)) {
            $procedureSetting = null;
        }

        $sortMap = [
            'orderNumber' => 'order_number',
            'orderedAt' => 'ordered_at',
            'scheduledFor' => 'scheduled_for',
            'status' => 'status',
            'procedureSetting' => 'procedure_setting',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'orderedAt';
        $sortBy = $sortMap[$sortBy] ?? 'ordered_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

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

        $encounterId = isset($filters['encounterId']) ? trim((string) $filters['encounterId']) : null;
        $encounterId = $encounterId === '' ? null : $encounterId;
        if ($encounterId !== null && ! Str::isUuid($encounterId)) {
            $encounterId = null;
        }

        $admissionId = isset($filters['admissionId']) ? trim((string) $filters['admissionId']) : null;
        $admissionId = $admissionId === '' ? null : $admissionId;
        if ($admissionId !== null && ! Str::isUuid($admissionId)) {
            $admissionId = null;
        }

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $statuses = null;
        $worklistScope = strtolower(trim((string) ($filters['worklistScope'] ?? '')));
        if ($status === null && $worklistScope === 'open') {
            $statuses = ClinicalProcedureOrderStatus::openWorklistValues();
        }

        return $this->clinicalProcedureOrderRepository->search(
            query: $query,
            patientId: $patientId,
            encounterId: $encounterId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            status: $status,
            statuses: $statuses,
            procedureSetting: $procedureSetting,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
