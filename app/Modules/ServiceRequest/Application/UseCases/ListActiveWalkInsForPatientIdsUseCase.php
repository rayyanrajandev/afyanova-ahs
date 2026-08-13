<?php

namespace App\Modules\ServiceRequest\Application\UseCases;

use App\Modules\ServiceRequest\Domain\Repositories\ServiceRequestRepositoryInterface;

class ListActiveWalkInsForPatientIdsUseCase
{
    public function __construct(
        private readonly ServiceRequestRepositoryInterface $serviceRequestRepository,
    ) {}

    /**
     * @param  array<int, string>  $patientIds
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function execute(array $patientIds): array
    {
        return $this->serviceRequestRepository->findActiveByPatientIds($patientIds);
    }
}
