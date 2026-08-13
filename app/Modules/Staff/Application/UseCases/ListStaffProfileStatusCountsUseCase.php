<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;

class ListStaffProfileStatusCountsUseCase
{
    public function __construct(private readonly StaffProfileRepositoryInterface $staffProfileRepository) {}

    public function execute(array $filters): array
    {
        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $employmentType = isset($filters['employmentType']) ? trim((string) $filters['employmentType']) : null;
        $employmentType = $employmentType === '' ? null : $employmentType;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->staffProfileRepository->statusCounts(
            query: $query,
            department: $department,
            employmentType: $employmentType,
        );
    }
}
