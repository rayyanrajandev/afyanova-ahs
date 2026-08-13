<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CrossTenantAdminAuditLogRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\CrossTenantAdminStaffProfileReadRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffProfileStatus;

class SearchCrossTenantAdminStaffProfilesUseCase
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly CrossTenantAdminStaffProfileReadRepositoryInterface $staffProfileReadRepository,
        private readonly CrossTenantAdminAuditLogRepositoryInterface $auditLogRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>|null
     */
    public function execute(array $filters, ?int $actorId): ?array
    {
        $targetTenantCode = strtoupper(trim((string) ($filters['targetTenantCode'] ?? '')));
        $reason = trim((string) ($filters['reason'] ?? ''));

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, StaffProfileStatus::values(), true)) {
            $status = null;
        }

        $department = isset($filters['department']) ? trim((string) $filters['department']) : null;
        $department = $department === '' ? null : $department;

        $employmentType = isset($filters['employmentType']) ? trim((string) $filters['employmentType']) : null;
        $employmentType = $employmentType === '' ? null : $employmentType;

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $sortMap = [
            'employeeNumber' => 'employee_number',
            'department' => 'department',
            'jobTitle' => 'job_title',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $requestedSortKey = (string) ($filters['sortBy'] ?? 'employeeNumber');
        $sortBy = $sortMap[$requestedSortKey] ?? 'employee_number';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $tenant = $this->tenantRepository->findByCode($targetTenantCode);

        if ($tenant === null) {
            $this->auditLogRepository->write(
                action: 'platform-admin.staff.search',
                operationType: 'read',
                actorId: $actorId,
                targetTenantId: null,
                targetTenantCode: $targetTenantCode !== '' ? $targetTenantCode : null,
                targetResourceType: 'staff_profile',
                targetResourceId: null,
                outcome: 'not_found',
                reason: $reason !== '' ? $reason : null,
                metadata: $this->auditMetadata(
                    query: $query,
                    status: $status,
                    department: $department,
                    employmentType: $employmentType,
                    page: $page,
                    perPage: $perPage,
                    sortBy: $sortBy,
                    sortDirection: $sortDirection,
                ),
            );

            return null;
        }

        $result = $this->staffProfileReadRepository->searchByTenantId(
            tenantId: (string) $tenant['id'],
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );

        $metadata = $this->auditMetadata(
            query: $query,
            status: $status,
            department: $department,
            employmentType: $employmentType,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
        $metadata['result'] = ['total' => $result['meta']['total'] ?? 0];

        $this->auditLogRepository->write(
            action: 'platform-admin.staff.search',
            operationType: 'read',
            actorId: $actorId,
            targetTenantId: (string) $tenant['id'],
            targetTenantCode: (string) ($tenant['code'] ?? $targetTenantCode),
            targetResourceType: 'staff_profile',
            targetResourceId: null,
            outcome: 'success',
            reason: $reason !== '' ? $reason : null,
            metadata: $metadata,
        );

        return [
            'data' => $result['data'],
            'meta' => [
                ...$result['meta'],
                'filters' => [
                    'targetTenantCode' => (string) ($tenant['code'] ?? $targetTenantCode),
                    'q' => $query,
                    'status' => $status,
                    'department' => $department,
                    'employmentType' => $employmentType,
                    'sortBy' => array_search($sortBy, $sortMap, true) ?: 'employeeNumber',
                    'sortDir' => $sortDirection,
                ],
                'targetTenant' => [
                    'id' => $tenant['id'] ?? null,
                    'code' => $tenant['code'] ?? null,
                    'name' => $tenant['name'] ?? null,
                    'countryCode' => $tenant['country_code'] ?? null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auditMetadata(
        ?string $query,
        ?string $status,
        ?string $department,
        ?string $employmentType,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection
    ): array {
        return [
            'filters' => [
                'q' => $query,
                'status' => $status,
                'department' => $department,
                'employmentType' => $employmentType,
                'page' => $page,
                'perPage' => $perPage,
                'sortBy' => $sortBy,
                'sortDir' => $sortDirection,
            ],
        ];
    }
}
