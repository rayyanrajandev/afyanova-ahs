<?php

namespace App\Modules\Pharmacy\Application\UseCases;

use App\Modules\Pharmacy\Domain\Repositories\PharmacyOrderRepositoryInterface;

class GetPharmacyOrderUseCase
{
    public function __construct(private readonly PharmacyOrderRepositoryInterface $pharmacyOrderRepository) {}

    public function execute(string $id): ?array
    {
        return $this->pharmacyOrderRepository->findById($id);
    }
}
