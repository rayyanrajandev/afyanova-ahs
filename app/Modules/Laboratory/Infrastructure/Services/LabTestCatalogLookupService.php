<?php

namespace App\Modules\Laboratory\Infrastructure\Services;

use App\Modules\Laboratory\Domain\Services\LabTestCatalogLookupServiceInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogItemStatus;
use App\Modules\Platform\Domain\ValueObjects\ClinicalCatalogType;
use App\Modules\Platform\Infrastructure\Models\ClinicalCatalogItemModel;
use Illuminate\Database\Eloquent\Builder;

class LabTestCatalogLookupService implements LabTestCatalogLookupServiceInterface
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function findActiveById(string $id): ?array
    {
        $query = $this->baseQuery()->where('id', $id);

        return $this->resolveScoped($query)?->toArray();
    }

    public function findActiveByCode(string $code): ?array
    {
        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return null;
        }

        $query = $this->baseQuery()->whereRaw('UPPER(code) = ?', [$normalizedCode]);

        return $this->resolveScoped($query)?->toArray();
    }

    /**
     * Facility-specific catalog rows take precedence, but a facility may still
     * use the shared global row (facility_id null) as a fallback. This lets one
     * facility offer "Urinalysis Dipstick only" while another offers
     * "Dipstick + Microscopy" under the same code — without duplicating the
     * investigation and without breaking tenants that run a single global
     * catalog.
     */
    private function resolveScoped(Builder $query): ?ClinicalCatalogItemModel
    {
        $facilityId = $this->scopeContext->facilityId();

        if ($facilityId !== null) {
            $item = (clone $query)->where('facility_id', $facilityId)->first();
            if ($item) {
                return $item;
            }

            $query->whereNull('facility_id');
        }

        return $query->first();
    }

    private function baseQuery(): Builder
    {
        return ClinicalCatalogItemModel::query()
            ->where('catalog_type', ClinicalCatalogType::LAB_TEST->value)
            ->where('status', ClinicalCatalogItemStatus::ACTIVE->value);
    }
}
