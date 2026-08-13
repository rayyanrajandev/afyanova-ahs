<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;

class ListInventoryDepartmentRequisitionsUseCase
{
    public function __construct(
        private readonly InventoryDepartmentRequisitionRepositoryInterface $requisitionRepository,
        private readonly InventoryDepartmentRequisitionLineRepositoryInterface $lineRepository,
    ) {}

    public function execute(array $filters): array
    {
        $result = $this->requisitionRepository->search(
            query: $filters['q'] ?? null,
            status: $filters['status'] ?? null,
            department: $filters['department'] ?? null,
            departmentId: $filters['departmentId'] ?? null,
            page: max(1, (int) ($filters['page'] ?? 1)),
            perPage: min(100, max(1, (int) ($filters['perPage'] ?? 25))),
            sortBy: $filters['sortBy'] ?? null,
            sortDirection: ($filters['sortDirection'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        );

        foreach ($result['data'] as &$requisition) {
            $requisition['lines'] = $this->lineRepository->listByRequisitionId($requisition['id']);
        }

        return $result;
    }
}
