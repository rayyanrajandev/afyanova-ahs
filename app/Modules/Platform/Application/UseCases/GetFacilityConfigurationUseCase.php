<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityConfigurationRepositoryInterface;

class GetFacilityConfigurationUseCase
{
    public function __construct(
        private readonly FacilityConfigurationRepositoryInterface $facilityConfigurationRepository
    ) {}

    public function execute(string $id): ?array
    {
        return $this->facilityConfigurationRepository->findById($id);
    }
}
