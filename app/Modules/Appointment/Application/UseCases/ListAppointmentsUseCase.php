<?php

namespace App\Modules\Appointment\Application\UseCases;

use App\Modules\Appointment\Domain\Repositories\AppointmentRepositoryInterface;
use App\Modules\Appointment\Domain\ValueObjects\AppointmentStatus;
use Illuminate\Support\Str;

class ListAppointmentsUseCase
{
    public function __construct(private readonly AppointmentRepositoryInterface $appointmentRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if ($status === '') {
            $status = null;
        } elseif ($status === 'checked_in') {
            // 'checked_in' is the legacy/frontend alias for the waiting_triage status.
            $status = 'waiting_triage';
        } elseif ($status !== 'exceptions' && ! in_array($status, AppointmentStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'appointmentNumber' => 'appointment_number',
            'scheduledAt' => 'scheduled_at',
            'checkedInAt' => 'checked_in_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'scheduledAt';
        $sortBy = $sortMap[$sortBy] ?? 'scheduled_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;
        if ($patientId !== null && ! Str::isUuid($patientId)) {
            $patientId = null;
        }

        $clinicianUserId = isset($filters['clinicianUserId']) ? (int) $filters['clinicianUserId'] : null;
        if ($clinicianUserId !== null && $clinicianUserId <= 0) {
            $clinicianUserId = null;
        }

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $unassignedClinicianOnly = $this->parseTruthyFilter($filters['unassignedClinician'] ?? null);

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        $triageCategory = isset($filters['triageCategory']) ? trim((string) $filters['triageCategory']) : null;
        $triageCategory = ($triageCategory === '' || ! in_array(strtoupper($triageCategory), ['P1', 'P2', 'P3', 'P4', 'P5'], true))
            ? null
            : strtoupper($triageCategory);

        return $this->appointmentRepository->search(
            query: $query,
            patientId: $patientId,
            clinicianUserId: $clinicianUserId,
            department: $department,
            unassignedClinicianOnly: $unassignedClinicianOnly,
            status: $status,
            triageCategory: $triageCategory,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }

    private function parseTruthyFilter(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
