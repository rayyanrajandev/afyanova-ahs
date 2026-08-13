<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;

class ListFacilityResourceStatusCountsUseCase
{
    public function __construct(private readonly FacilityResourceRepositoryInterface $facilityResourceRepository) {}

    public function execute(string $resourceType, array $filters): array
    {
        if (! in_array($resourceType, FacilityResourceType::values(), true)) {
            return [
                'active' => 0,
                'inactive' => 0,
                'other' => 0,
                'total' => 0,
            ];
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

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        return $this->facilityResourceRepository->statusCounts(
            resourceType: $resourceType,
            query: $query,
            departmentId: $departmentId,
            subtype: $subtype,
        );
    }
}

