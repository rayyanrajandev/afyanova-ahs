<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;

class ListClinicalCatalogItemStatusCountsUseCase
{
    public function __construct(private readonly ClinicalCatalogItemRepositoryInterface $repository) {}

    public function execute(string $catalogType, array $filters): array
    {
        if (! in_array($catalogType, ClinicalCatalogType::values(), true)) {
            return [
                'active' => 0,
                'inactive' => 0,
                'retired' => 0,
                'other' => 0,
                'total' => 0,
            ];
        }

        $query = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $query = $query === '' ? null : $query;

        $departmentId = isset($filters['departmentId']) ? trim((string) $filters['departmentId']) : null;
        $departmentId = $departmentId === '' ? null : $departmentId;

        $category = isset($filters['category']) ? trim((string) $filters['category']) : null;
        $category = $category === '' ? null : $category;

        $dosageForm = isset($filters['dosageForm']) ? trim((string) $filters['dosageForm']) : null;
        $dosageForm = $dosageForm === '' ? null : $dosageForm;

        return $this->repository->statusCounts(
            catalogType: $catalogType,
            query: $query,
            departmentId: $departmentId,
            category: $category,
            dosageForm: $dosageForm,
        );
    }
}
