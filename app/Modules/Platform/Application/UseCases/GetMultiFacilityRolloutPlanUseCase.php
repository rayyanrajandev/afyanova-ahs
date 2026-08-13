<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\MultiFacilityRolloutRepositoryInterface;

class GetMultiFacilityRolloutPlanUseCase
{
    public function __construct(private readonly MultiFacilityRolloutRepositoryInterface $rolloutRepository) {}

    public function execute(string $id): ?array
    {
        return $this->rolloutRepository->findPlanById($id);
    }
}
