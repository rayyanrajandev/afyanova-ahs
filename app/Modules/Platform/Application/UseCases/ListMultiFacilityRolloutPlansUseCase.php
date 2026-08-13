<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\MultiFacilityRolloutPlanStatus;

class ListMultiFacilityRolloutPlansUseCase
{
    public function __construct(private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, MultiFacilityRolloutPlanStatus::values(), true)) {
            $status = null;
        }

        $facilityId = isset($filters['facilityId']) ? trim((string) $filters['facilityId']) : null;
        $facilityId = $facilityId === '' ? null : $facilityId;

        $goLiveFrom = isset($filters['goLiveFrom']) ? trim((string) $filters['goLiveFrom']) : null;
        $goLiveFrom = $goLiveFrom === '' ? null : $goLiveFrom;

        $goLiveTo = isset($filters['goLiveTo']) ? trim((string) $filters['goLiveTo']) : null;
        $goLiveTo = $goLiveTo === '' ? null : $goLiveTo;

        $sortMap = [
            'rolloutCode' => 'rollout_code',
            'status' => 'status',
            'targetGoLiveAt' => 'target_go_live_at',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'targetGoLiveAt'] ?? 'target_go_live_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->rolloutRepository->searchPlans(
            query: $query,
            status: $status,
            facilityId: $facilityId,
            goLiveFrom: $goLiveFrom,
            goLiveTo: $goLiveTo,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
