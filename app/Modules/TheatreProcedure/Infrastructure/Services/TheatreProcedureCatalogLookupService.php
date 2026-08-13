<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Services;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\TheatreProcedure\Domain\Services\TheatreProcedureCatalogLookupServiceInterface;
use Illuminate\Database\Eloquent\Builder;

class TheatreProcedureCatalogLookupService implements TheatreProcedureCatalogLookupServiceInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function findActiveById(string $id): ?array
    {
        $query = ClinicalCatalogItemModel::query()
            ->where('id', $id);

        $this->applyCommonFilters($query);
        $item = $query->first();

        return $item?->toArray();
    }

    public function findActiveByCode(string $code): ?array
    {
        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return null;
        }

        $query = ClinicalCatalogItemModel::query()
            ->whereRaw('UPPER(code) = ?', [$normalizedCode]);

        $this->applyCommonFilters($query);
        $item = $query->first();

        return $item?->toArray();
    }

    private function applyCommonFilters(Builder $query): void
    {
        $query
            ->where('catalog_type', ClinicalCatalogType::THEATRE_PROCEDURE->value)
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value);

        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply($query);
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
