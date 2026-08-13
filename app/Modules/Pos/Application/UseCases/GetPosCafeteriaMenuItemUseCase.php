<?php

namespace App\Modules\Pos\Application\UseCases;

use App\Modules\Pos\Domain\Repositories\PosCafeteriaMenuItemRepositoryInterface;

class GetPosCafeteriaMenuItemUseCase
{
    public function __construct(
        private readonly PosCafeteriaMenuItemRepositoryInterface $posCafeteriaMenuItemRepository,
    ) {}

    public function execute(string $id): ?array
    {
        return $this->posCafeteriaMenuItemRepository->findById($id);
    }
}
