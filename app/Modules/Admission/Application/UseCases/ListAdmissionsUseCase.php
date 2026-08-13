<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Admission\Domain\ValueObjects\AdmissionStatus;

class ListAdmissionsUseCase
{
    public function __construct(private readonly AdmissionRepositoryInterface $admissionRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, AdmissionStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'admissionNumber' => 'admission_number',
            'admittedAt' => 'admitted_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];

        $sortBy = $filters['sortBy'] ?? 'admittedAt';
        $sortBy = $sortMap[$sortBy] ?? 'admitted_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $patientId = isset($filters['patientId']) ? trim((string) $filters['patientId']) : null;
        $patientId = $patientId === '' ? null : $patientId;

        $ward = isset($filters['ward']) ? trim((string) $filters['ward']) : null;
        $ward = $ward === '' ? null : $ward;

        $fromDateTime = isset($filters['from']) ? trim((string) $filters['from']) : null;
        $fromDateTime = $fromDateTime === '' ? null : $fromDateTime;

        $toDateTime = isset($filters['to']) ? trim((string) $filters['to']) : null;
        $toDateTime = $toDateTime === '' ? null : $toDateTime;

        return $this->admissionRepository->search(
            query: $query,
            patientId: $patientId,
            status: $status,
            ward: $ward,
            fromDateTime: $fromDateTime,
            toDateTime: $toDateTime,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
