<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;

class GetServiceRequestUseCase
{
    public function __construct(private readonly ServiceRequestRepositoryInterface $serviceRequestRepository) {}

    public function execute(string $id): ?array
    {
        return $this->serviceRequestRepository->findById($id);
    }
}
