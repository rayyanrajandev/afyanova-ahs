<?php

namespace App\Modules\InventoryProcurement\Application\UseCases;

use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionLineRepositoryInterface;
use App\Modules\InventoryProcurement\Domain\Repositories\InventoryDepartmentRequisitionRepositoryInterface;

class GetInventoryDepartmentRequisitionUseCase
{
    public function __construct(
        private readonly InventoryDepartmentRequisitionRepositoryInterface $requisitionRepository,
        private readonly InventoryDepartmentRequisitionLineRepositoryInterface $lineRepository,
    ) {}

    public function execute(string $id): ?array
    {
        $requisition = $this->requisitionRepository->findById($id);
        if (! $requisition) {
            return null;
        }

        $requisition['lines'] = $this->lineRepository->listByRequisitionId($id);

        return $requisition;
    }
}
