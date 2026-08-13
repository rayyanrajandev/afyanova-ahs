<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FacilityRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;

class EloquentFacilityRepository implements FacilityRepositoryInterface
{
    public function findByCode(string $code, ?string $tenantId = null): ?array
    {
        $query = FacilityModel::query()
            ->where('code', strtoupper(trim($code)));

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $facility = $query->first();

        return $facility?->toArray();
    }

    public function findById(string $id): ?array
    {
        $facility = FacilityModel::query()->find($id);

        return $facility?->toArray();
    }
}
