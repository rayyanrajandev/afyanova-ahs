<?php

namespace App\Support\CatalogGovernance;

use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class FacilityTierSupport
{
    /**
     * @var array<int, string>
     */
    public const TIERS = [
        'dispensary',
        'health_centre',
        'district_hospital',
        'regional_hospital',
        'zonal_referral',
    ];

    /**
     * @return array<int, string>
     */
    public function availableMinimumTiersForFacility(?string $facilityId): array
    {
        $facilityTier = $this->facilityTier($facilityId);
        if ($facilityTier === null) {
            return self::TIERS;
        }

        $facilityRank = $this->rank($facilityTier);

        return array_values(array_filter(
            self::TIERS,
            fn (string $tier): bool => $this->rank($tier) <= $facilityRank,
        ));
    }

    public function applyAvailabilityFilter(Builder $query, string $tableName, ?string $facilityId): void
    {
        if (! Schema::hasColumn($tableName, 'facility_tier')) {
            return;
        }

        $availableTiers = $this->availableMinimumTiersForFacility($facilityId);
        $qualifiedColumn = $tableName.'.facility_tier';

        $query->where(function (Builder $builder) use ($availableTiers, $qualifiedColumn): void {
            $builder->whereNull($qualifiedColumn)
                ->orWhereIn($qualifiedColumn, $availableTiers);
        });
    }

    public function isValid(?string $tier): bool
    {
        return $tier === null || in_array($tier, self::TIERS, true);
    }

    public function normalize(mixed $tier): ?string
    {
        if ($tier === null) {
            return null;
        }

        $normalized = strtolower(trim(str_replace([' ', '-'], '_', (string) $tier)));

        return in_array($normalized, self::TIERS, true) ? $normalized : null;
    }

    private function facilityTier(?string $facilityId): ?string
    {
        if ($facilityId === null || $facilityId === '') {
            return null;
        }

        $facility = FacilityModel::query()->find($facilityId);
        if ($facility === null) {
            return null;
        }

        if (Schema::hasColumn('facilities', 'facility_tier')) {
            $tier = $this->normalize($facility->getAttribute('facility_tier'));
            if ($tier !== null) {
                return $tier;
            }
        }

        return $this->inferTierFromFacilityType((string) ($facility->facility_type ?? ''));
    }

    private function inferTierFromFacilityType(string $facilityType): ?string
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($facilityType)));

        return match (true) {
            str_contains($normalized, 'dispensary') => 'dispensary',
            str_contains($normalized, 'health_centre'), str_contains($normalized, 'health_center') => 'health_centre',
            str_contains($normalized, 'district') => 'district_hospital',
            str_contains($normalized, 'regional') => 'regional_hospital',
            str_contains($normalized, 'zonal'), str_contains($normalized, 'referral'), str_contains($normalized, 'national') => 'zonal_referral',
            default => null,
        };
    }

    private function rank(string $tier): int
    {
        $rank = array_search($tier, self::TIERS, true);

        return is_int($rank) ? $rank : 0;
    }
}
