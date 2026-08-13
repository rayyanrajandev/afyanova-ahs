<?php

namespace App\Modules\Laboratory\Application\UseCases;

use App\Modules\Laboratory\Domain\Repositories\LaboratoryOrderRepositoryInterface;

class GetLaboratoryOrderUseCase
{
    public function __construct(private readonly LaboratoryOrderRepositoryInterface $laboratoryOrderRepository) {}

    public function execute(string $id): ?array
    {
        return $this->laboratoryOrderRepository->findById($id);
    }
}
