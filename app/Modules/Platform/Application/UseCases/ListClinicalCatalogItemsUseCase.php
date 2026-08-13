<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;

class ListClinicalCatalogItemsUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly ClinicalCatalogBillingLinkEnricher $billingLinkEnricher,
    ) {}

    public function execute(string $catalogType, array $filters): array
    {
        if (! in_array($catalogType, ClinicalCatalogType::values(), true)) {
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
        $perPage = min(max((int) ($filters['perPage'] ?? 10), 1), 2000);

        $sortMap = [
            'code' => 'code',
            'name' => 'name',
            'category' => 'category',
            'status' => 'status',
            'updatedAt' => 'updated_at',
            'createdAt' => 'created_at',
        ];
        $sortBy = $filters['sortBy'] ?? 'name';
        $sortBy = $sortMap[$sortBy] ?? 'name';

        $sortDirection = strtolower((string) ($filters['sortDir'] ?? 'asc'));
        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $status = isset($filters['status']) ? strtolower(trim((string) $filters['status'])) : null;
        $status = in_array($status, ClinicalCatalogItemStatus::values(), true) ? $status : null;

        $departmentId = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        $departmentId = $departmentId === '' ? null : $departmentId;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $dosageForm = isset($filters['dosageForm']) ? trim((string) $filters['dosageForm']) : null;
        $dosageForm = $dosageForm === '' ? null : $dosageForm;

        $result = $this->repository->search(
            catalogType: $catalogType,
            query: $query,
            status: $status,
            departmentId: $departmentId,
            category: $category,
            dosageForm: $dosageForm,
            page: $page,
            perPage: $perPage,
            sortBy: $sortBy,
            sortDirection: $sortDirection,
        );

        $result['data'] = $this->billingLinkEnricher->enrichMany($result['data'] ?? []);

        return $result;
    }
}
