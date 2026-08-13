<?php

namespace App\Modules\Admission\Application\UseCases;

use App\Modules\Admission\Domain\Repositories\AdmissionRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\FacilityResourceType;

/**
 * Server-side ward-bed list joined with occupancy — replaces the client-side
 * cross-reference the legacy admissions/Index.vue did against its own
 * loaded admissions list (wardBedRegistry/WardRegistryBedOption). Lives in
 * the Admission module even though its base query is a Platform repository
 * — same cross-module shape AdmissionPlacementLookupService already uses.
 */
class ListAvailableBedsUseCase
{
    public function __construct(
        private readonly FacilityResourceRepositoryInterface $facilityResourceRepository,
        private readonly AdmissionRepositoryInterface $admissionRepository,
    ) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 200), 1), 200);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $wardName = isset($filters['wardName']) ? trim((string) $filters['wardName']) : null;
        $wardName = $wardName === '' ? null : $wardName;

        $departmentId = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        $departmentId = $departmentId === '' ? null : $departmentId;

        // Ward-beds and observation-rooms are both valid admission
        // placements (see AdmissionPlacementLookupService::
        // validatePlacementByResource()) — offer both in one list rather
        // than hiding observation-rooms from the picker entirely.
        $result = $this->facilityResourceRepository->searchAcrossResourceTypes(
            resourceTypes: [FacilityResourceType::WARD_BED->value, FacilityResourceType::OBSERVATION_ROOM->value],
            query: $query,
            status: 'active',
            departmentId: $departmentId,
            subtype: $wardName,
            page: $page,
            perPage: $perPage,
            sortBy: 'name',
            sortDirection: 'asc',
        );

        $beds = $result['data'];
        $bedIds = array_values(array_filter(array_map(
            static fn (array $bed): ?string => $bed['id'] ?? null,
            $beds,
        )));

        $occupancy = $bedIds === [] ? [] : $this->admissionRepository->activeAdmissionsByBedResourceIds($bedIds);

        $result['data'] = array_map(function (array $bed) use ($occupancy): array {
            $occupyingAdmission = $occupancy[(string) ($bed['id'] ?? '')] ?? null;
            $bed['is_occupied'] = $occupyingAdmission !== null;
            $bed['occupied_by_admission_id'] = $occupyingAdmission['id'] ?? null;
            $bed['occupied_by_admission_number'] = $occupyingAdmission['admission_number'] ?? null;

            return $bed;
        }, $beds);

        return $result;
    }
}
