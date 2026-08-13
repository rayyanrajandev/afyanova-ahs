<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\StaffPrivilegeGrantRepositoryInterface;
use App\Modules\Staff\Domain\Repositories\StaffProfileRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\StaffPrivilegeGrantStatus;

class ListStaffPrivilegeGrantsUseCase
{
    public function __construct(
        private readonly StaffProfileRepositoryInterface $staffProfileRepository,
        private readonly StaffPrivilegeGrantRepositoryInterface $staffPrivilegeGrantRepository,
    ) {}

    public function execute(string $staffProfileId, array $filters): ?array
    {
        $profile = $this->staffProfileRepository->findById($staffProfileId);
        if (! $profile) {
            return null;
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 100);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $facilityId = isset($filters['facilityId']) ? trim((string) $filters['facilityId']) : null;
        $facilityId = $facilityId === '' ? null : $facilityId;

        $specialtyId = isset($filters['specialtyId']) ? trim((string) $filters['specialtyId']) : null;
        $specialtyId = $specialtyId === '' ? null : $specialtyId;

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        if (! in_array($status, StaffPrivilegeGrantStatus::values(), true)) {
            $status = null;
        }

        $grantedFrom = isset($filters['grantedFrom']) ? trim((string) $filters['grantedFrom']) : null;
        $grantedFrom = $grantedFrom === '' ? null : $grantedFrom;

        $grantedTo = isset($filters['grantedTo']) ? trim((string) $filters['grantedTo']) : null;
        $grantedTo = $grantedTo === '' ? null : $grantedTo;

        $reviewDueFrom = isset($filters['reviewDueFrom']) ? trim((string) $filters['reviewDueFrom']) : null;
        $reviewDueFrom = $reviewDueFrom === '' ? null : $reviewDueFrom;

        $reviewDueTo = isset($filters['reviewDueTo']) ? trim((string) $filters['reviewDueTo']) : null;
        $reviewDueTo = $reviewDueTo === '' ? null : $reviewDueTo;

        $sortMap = [
            'privilegeCode' => 'privilege_code',
            'privilegeName' => 'privilege_name',
            'facilityId' => 'facility_id',
            'specialtyId' => 'specialty_id',
            'grantedAt' => 'granted_at',
            'reviewDueAt' => 'review_due_at',
            'status' => 'status',
            'createdAt' => 'created_at',
            'updatedAt' => 'updated_at',
        ];
        $sortBy = $sortMap[$filters['sortBy'] ?? 'grantedAt'] ?? 'granted_at';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'desc'));
        $sortDirection = $sortDirection === 'asc' ? 'asc' : 'desc';

        return $this->staffPrivilegeGrantRepository->searchByStaffProfileId(
            staffProfileId: $staffProfileId,
            query: $query,
            facilityId: $facilityId,
            specialtyId: $specialtyId,
            status: $status,
            grantedFrom: $grantedFrom,
            grantedTo: $grantedTo,
            reviewDueFrom: $reviewDueFrom,
            reviewDueTo: $reviewDueTo,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}

