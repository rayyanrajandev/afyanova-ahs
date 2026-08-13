<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceStatus;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;

class ListFacilityResourcesUseCase
{
    public function __construct(private readonly FacilityResourceRepositoryInterface $facilityResourceRepository) {}

    public function execute(string $resourceType, array $filters): array
    {
        if (! in_array($resourceType, FacilityResourceType::values(), true)) {
            return [
                'data' => [],
                'meta' => [
                    'currentPage' => 1,
                    'perPage' => 0,
                    'total' => 0,
                    'lastPage' => 1,
                ],
            ];
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 15), 1), 100);

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, FacilityResourceStatus::values(), true)) {
            $status = null;
        }

        $departmentId = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        $departmentId = $departmentId === '' ? null : $departmentId;

        $subtype = null;
        if ($resourceType === FacilityResourceType::SERVICE_POINT->value) {
            $subtype = isset($filters['servicePointType']) ? trim((string) $filters['servicePointType']) : null;
        }
        if ($resourceType === FacilityResourceType::WARD_BED->value) {
            $subtype = isset($filters['wardName']) ? trim((string) $filters['wardName']) : null;
        }
        if ($resourceType === FacilityResourceType::OBSERVATION_ROOM->value) {
            $subtype = isset($filters['roomName']) ? trim((string) $filters['roomName']) : null;
        }
        $subtype = $subtype === '' ? null : $subtype;

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

        return $this->facilityResourceRepository->search(
            resourceType: $resourceType,
            query: $query,
            status: $status,
            departmentId: $departmentId,
            subtype: $subtype,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

