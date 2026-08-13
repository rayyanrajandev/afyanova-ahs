<?php

namespace App\Modules\Staff\Application\UseCases;

use App\Modules\Staff\Domain\Repositories\ClinicalPrivilegeCatalogRepositoryInterface;
use App\Modules\Staff\Domain\ValueObjects\ClinicalPrivilegeCatalogStatus;

class ListClinicalPrivilegeCatalogsUseCase
{
    public function __construct(private readonly ClinicalPrivilegeCatalogRepositoryInterface $clinicalPrivilegeCatalogRepository) {}

    public function execute(array $filters): array
    {
        $page = max((int) ($filters['page'] ?? 1), 1);
        $perPage = min(max((int) ($filters['perPage'] ?? 20), 1), 200);

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, ClinicalPrivilegeCatalogStatus::values(), true) ? $status : null;

        $specialtyId = isset($filters['specialtyId']) ? trim((string) $filters['specialtyId']) : null;
        $specialtyId = $specialtyId === '' ? null : $specialtyId;

        $cadreCode = isset($filters['cadreCode']) ? trim((string) $filters['cadreCode']) : null;
        $cadreCode = $cadreCode === '' ? null : $cadreCode;

        $facilityType = isset($filters['facilityType']) ? trim((string) $filters['facilityType']) : null;
        $facilityType = $facilityType === '' ? null : $facilityType;

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

        return $this->clinicalPrivilegeCatalogRepository->search(
            query: $query,
            status: $status,
            specialtyId: $specialtyId,
            cadreCode: $cadreCode,
            facilityType: $facilityType,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );
    }
}
