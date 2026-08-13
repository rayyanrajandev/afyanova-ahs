<?php

namespace App\Modules\EmergencyTriage\Application\UseCases;

use App\Modules\EmergencyTriage\Domain\Repositories\EmergencyTriageCaseRepositoryInterface;

class GetEmergencyTriageCaseUseCase
{
    public function __construct(private readonly EmergencyTriageCaseRepositoryInterface $emergencyTriageCaseRepository) {}

    public function execute(string $id): ?array
    {
        return $this->emergencyTriageCaseRepository->findById($id);
    }
}
