<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosSaleRepositoryInterface;

class GetPosSaleUseCase
{
    public function __construct(private readonly PosSaleRepositoryInterface $posSaleRepository) {}

    public function execute(string $id): ?array
    {
        return $this->posSaleRepository->findById($id);
    }
}
