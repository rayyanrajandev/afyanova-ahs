<?php

namespace App\Modules\MedicalRecord\Infrastructure\Services;

use App\Modules\MedicalRecord\Domain\Services\TheatreProcedureLookupServiceInterface;
use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;

class TheatreProcedureLookupService implements TheatreProcedureLookupServiceInterface
{
    public function __construct(
        private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository,
    ) {}

    public function findById(string $theatreProcedureId): ?array
    {
        return $this->theatreProcedureRepository->findById($theatreProcedureId);
    }
}
