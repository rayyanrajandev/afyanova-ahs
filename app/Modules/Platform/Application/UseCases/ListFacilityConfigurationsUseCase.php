<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityConfigurationStatus;

class ListFacilityConfigurationsUseCase
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        if (! in_array($status, FacilityConfigurationStatus::values(), true)) {
            $status = null;
        }

        $facilityType = isset($filters['facilityType']) ? trim((string) $filters['facilityType']) : null;
        $facilityType = $facilityType === '' ? null : $facilityType;

        $ownerUserIdInput = isset($filters['ownerUserId']) ? trim((string) $filters['ownerUserId']) : null;
        $ownerUserIdInput = $ownerUserIdInput === '' ? null : $ownerUserIdInput;
        $ownerUserId = $ownerUserIdInput !== null && ctype_digit($ownerUserIdInput)
            ? (int) $ownerUserIdInput
            : null;

        $sortMap = [
            'code' => 'code',
            'name' => 'name',
            'facilityType' => 'facility_type',
            'timezone' => 'timezone',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'name'] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $this->facilityConfigurationRepository->search(
            query: $query,
            status: $status,
            facilityType: $facilityType,
            ownerUserId: $ownerUserId,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
