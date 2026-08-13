<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Application\Support\ClinicalCatalogBillingLinkEnricher;
use App\Modules\Platform\Domain\Repositories\ClinicalCatalogItemRepositoryInterface;

class GetClinicalCatalogItemUseCase
{
    public function __construct(
        private readonly ClinicalCatalogItemRepositoryInterface $repository,
        private readonly ClinicalCatalogBillingLinkEnricher $billingLinkEnricher,
    ) {}

    public function execute(string $id, string $catalogType): ?array
    {
        $item = $this->repository->findById($id);
        if (! $item) {
            return null;
        }

        if (($item['catalog_type'] ?? null) !== $catalogType) {
            return null;
        }

        return $this->billingLinkEnricher->enrich($item);
    }
}
