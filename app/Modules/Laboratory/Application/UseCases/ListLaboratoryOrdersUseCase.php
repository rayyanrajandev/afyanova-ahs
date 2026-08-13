<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;
use App\Modules\Laboratory\Domain\ValueObjects\LaboratoryOrderStatus;
use Illuminate\Support\Str;

class ListLaboratoryOrdersUseCase
{
    public function __construct(private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, LaboratoryOrderStatus::values(), true)) {
            $status = null;
        }

        $priority = isset($filters['priority']) ? strtolower(trim((string) $filters['priority'])) : null;
        if (! in_array($priority, ['routine', 'urgent', 'stat'], true)) {
            $priority = null;
        }

        $sortMap = [
            'orderNumber' => 'order_number',
            'orderedAt' => 'ordered_at',
            'status' => 'status',
            'priority' => 'priority',
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
            $statuses = LaboratoryOrderStatus::openWorklistValues();
        }

        return $this->laboratoryOrderRepository->search(
            query: $query,
            patientId: $patientId,
            encounterId: $encounterId,
            appointmentId: $appointmentId,
            admissionId: $admissionId,
            status: $status,
            statuses: $statuses,
            priority: $priority,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
