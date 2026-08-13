<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FacilityResourceRepositoryInterface;

class GetFacilityResourceUseCase
{
    public function __construct(private readonly FacilityResourceRepositoryInterface $facilityResourceRepository) {}

    public function execute(string $id, string $resourceType): ?array
    {
        $resource = $this->facilityResourceRepository->findById($id);
        if (! $resource) {
            return null;
        }

        if (($resource['resource_type'] ?? null) !== $resourceType) {
            return null;
        }

        return $resource;
    }
}

