<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;

class ListClinicalCatalogItemTypeCountsUseCase
{
    public function __construct(private readonly ClinicalCatalogItemRepositoryInterface $repository) {}

    public function execute(array $filters): array
    {
        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $departmentId = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        $departmentId = $departmentId === '' ? null : $departmentId;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $dosageForm = isset($filters['dosageForm']) ? trim((string) $filters['dosageForm']) : null;
        $dosageForm = $dosageForm === '' ? null : $dosageForm;

        return $this->repository->typeCounts(
            query: $query,
            departmentId: $departmentId,
            category: $category,
            dosageForm: $dosageForm,
        );
    }
}
