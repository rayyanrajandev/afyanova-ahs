<?php

namespace App\Modules\Department\Application\UseCases;

use App\Modules\Department\Domain\Repositories\DepartmentRepositoryInterface;
use App\Modules\Department\Domain\ValueObjects\DepartmentStatus;

class ListDepartmentsUseCase
{
    public function __construct(private readonly DepartmentRepositoryInterface $departmentRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = $filters['status'] ?? null;
        if (! in_array($status, DepartmentStatus::values(), true)) {
            $status = null;
        }

        $serviceType = isset($filters['serviceType']) ? trim((string) $filters['serviceType']) : null;
        $serviceType = $serviceType === '' ? null : $serviceType;

        $managerUserIdInput = isset($filters['managerUserId']) ? trim((string) $filters['managerUserId']) : null;
        $managerUserIdInput = $managerUserIdInput === '' ? null : $managerUserIdInput;
        $managerUserId = $managerUserIdInput !== null && ctype_digit($managerUserIdInput)
            ? (int) $managerUserIdInput
            : null;

        $sortMap = [
            'code' => 'code',
            'name' => 'name',
            'serviceType' => 'service_type',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'name'] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->departmentRepository->search(
            query: $query,
            status: $status,
            serviceType: $serviceType,
            managerUserId: $managerUserId,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

