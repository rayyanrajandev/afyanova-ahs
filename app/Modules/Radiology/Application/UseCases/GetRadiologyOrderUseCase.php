<?php

namespace App\Modules\Radiology\Application\UseCases;

use App\Modules\Radiology\Domain\Repositories\RadiologyOrderRepositoryInterface;

class GetRadiologyOrderUseCase
{
    public function __construct(private readonly RadiologyOrderRepositoryInterface $radiologyOrderRepository) {}

    public function execute(string $id): ?array
    {
        return $this->radiologyOrderRepository->findById($id);
    }
}
