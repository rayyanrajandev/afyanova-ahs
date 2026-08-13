<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;

class ListDepartmentStatusCountsUseCase
{
    public function __construct(private readonly DepartmentRepositoryInterface $departmentRepository) {}

    public function execute(array $filters): array
    {
        $serviceType = isset($filters['serviceType']) ? trim((string) $filters['serviceType']) : null;
        $serviceType = $serviceType === '' ? null : $serviceType;

        $managerUserIdInput = isset($filters['managerUserId']) ? trim((string) $filters['managerUserId']) : null;
        $managerUserIdInput = $managerUserIdInput === '' ? null : $managerUserIdInput;
        $managerUserId = $managerUserIdInput !== null && ctype_digit($managerUserIdInput)
            ? (int) $managerUserIdInput
            : null;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->departmentRepository->statusCounts(
            query: $query,
            serviceType: $serviceType,
            managerUserId: $managerUserId,
        );
    }
}

