<?php

namespace App\Modules\ClinicalProcedure\Application\UseCases;

use App\Modules\ClinicalProcedure\Domain\Repositories\ClinicalProcedureOrderRepositoryInterface;

class GetClinicalProcedureOrderUseCase
{
    public function __construct(private readonly ClinicalProcedureOrderRepositoryInterface $clinicalProcedureOrderRepository) {}

    public function execute(string $id): ?array
    {
        return $this->clinicalProcedureOrderRepository->findById($id);
    }
}
