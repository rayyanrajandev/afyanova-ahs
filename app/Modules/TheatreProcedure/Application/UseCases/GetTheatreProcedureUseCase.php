<?php

namespace App\Modules\TheatreProcedure\Application\UseCases;

use App\Modules\TheatreProcedure\Domain\Repositories\TheatreProcedureRepositoryInterface;

class GetTheatreProcedureUseCase
{
    public function __construct(private readonly TheatreProcedureRepositoryInterface $theatreProcedureRepository) {}

    public function execute(string $id): ?array
    {
        return $this->theatreProcedureRepository->findById($id);
    }
}
