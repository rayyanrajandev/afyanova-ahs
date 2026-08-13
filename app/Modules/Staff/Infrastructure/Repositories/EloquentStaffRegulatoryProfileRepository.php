<?php

namespace App\Modules\Staff\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Infrastructure\Support\PlatformScopeQueryApplier;
use App\Modules\Staff\Domain\Repositories\StaffRegulatoryProfileRepositoryInterface;
use App\Modules\Staff\Infrastructure\Models\StaffRegulatoryProfileModel;
use Illuminate\Database\Eloquent\Builder;

class EloquentStaffRegulatoryProfileRepository implements StaffRegulatoryProfileRepositoryInterface
{
    public function __construct(
        private readonly PlatformScopeQueryApplier $platformScopeQueryApplier,
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
    ) {}

    public function create(array $attributes): array
    {
        $profile = new StaffRegulatoryProfileModel();
        $profile->fill($attributes);
        $profile->save();

        return $profile->toArray();
    }

    public function findById(string $id): ?array
    {
        $query = StaffRegulatoryProfileModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $profile = $query->find($id);

        return $profile?->toArray();
    }

    public function findByStaffProfileId(string $staffProfileId): ?array
    {
        $query = StaffRegulatoryProfileModel::query()
            ->where('staff_profile_id', $staffProfileId);
        $this->applyTenantScopeIfEnabled($query);
        $profile = $query->first();

        return $profile?->toArray();
    }

    public function findByStaffProfileIds(array $staffProfileIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $staffProfileIds),
            static fn (string $value): bool => $value !== '',
        )));

        if ($ids === []) {
            return [];
        }

        $query = StaffRegulatoryProfileModel::query()
            ->whereIn('staff_profile_id', $ids);
        $this->applyTenantScopeIfEnabled($query);

        $profiles = [];
        foreach ($query->get() as $profile) {
            $payload = $profile->toArray();
            $staffProfileId = trim((string) ($payload['staff_profile_id'] ?? ''));

            if ($staffProfileId === '') {
                continue;
            }

            $profiles[$staffProfileId] = $payload;
        }

        return $profiles;
    }

    public function update(string $id, array $attributes): ?array
    {
        $query = StaffRegulatoryProfileModel::query();
        $this->applyTenantScopeIfEnabled($query);
        $profile = $query->find($id);
        if (! $profile) {
            return null;
        }

        $profile->fill($attributes);
        $profile->save();

        return $profile->toArray();
    }

    private function applyTenantScopeIfEnabled(Builder $query): void
    {
        if (! $this->isPlatformScopingEnabled()) {
            return;
        }

        $this->platformScopeQueryApplier->apply(
            $query,
            tenantColumn: 'tenant_id',
            facilityColumn: null,
        );
    }

    private function isPlatformScopingEnabled(): bool
    {
        return $this->featureFlagResolver->isEnabled('platform.multi_facility_scoping')
            || $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation');
    }
}
