<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\PlatformRbacRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\PlatformRoleStatus;

class ListPlatformRolesUseCase
{
    public function __construct(private readonly PlatformRbacRepositoryInterface $platformRbacRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, PlatformRoleStatus::values(), true)) {
            $status = null;
        }

        $sortMap = [
            'code' => 'code',
            'name' => 'name',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'name'] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $facilityId = isset($filters['facilityId']) && is_string($filters['facilityId']) && $filters['facilityId'] !== ''
            ? $filters['facilityId']
            : null;

        $assignableOnly = (bool) ($filters['assignableOnly'] ?? false);

        return $this->platformRbacRepository->searchRoles(
            query: $query,
            status: $status,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
            facilityId: $facilityId,
            assignableOnly: $assignableOnly,
        );
    }
}

