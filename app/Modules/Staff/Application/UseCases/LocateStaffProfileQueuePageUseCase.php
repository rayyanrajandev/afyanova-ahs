<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfileStatus;

class LocateStaffProfileQueuePageUseCase
{
    public function __construct(private readonly StaffProfileRepositoryInterface $staffProfileRepository) {}

    /**
     * @return array{page:int, position:int}|null
     */
    public function execute(string $staffProfileId, array $filters): ?array
    {
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, StaffProfileStatus::values(), true)) {
            $status = null;
        }

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $employmentType = isset($filters['employmentType']) ? trim((string) $filters['employmentType']) : null;
        $employmentType = $employmentType === '' ? null : $employmentType;
        $clinicalOnly = filter_var($filters['clinicalOnly'] ?? false, FILTER_VALIDATE_BOOL);

        $sortMap = [
            'employeeNumber' => 'employee_number',
            'department' => 'department',
            'jobTitle' => 'job_title',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'employeeNumber'] ?? 'employee_number';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->staffProfileRepository->locateInSearch(
            staffProfileId: $staffProfileId,
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            clinicalOnly: $clinicalOnly,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
