<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosRegisterRepositoryInterface;

class GetPosRegisterUseCase
{
    public function __construct(private readonly PosRegisterRepositoryInterface $posRegisterRepository) {}

    public function execute(string $id): ?array
    {
        return $this->posRegisterRepository->findById($id);
    }
}
