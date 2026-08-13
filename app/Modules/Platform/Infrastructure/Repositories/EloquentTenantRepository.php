<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\TenantModel;

class EloquentTenantRepository implements TenantRepositoryInterface
{
    public function findByCode(string $code): ?array
    {
        $tenant = TenantModel::query()
            ->where('code', strtoupper(trim($code)))
            ->first();

        return $tenant?->toArray();
    }

    public function findById(string $id): ?array
    {
        $tenant = TenantModel::query()->find($id);

        return $tenant?->toArray();
    }

    public function create(array $attributes): array
    {
        $tenant = TenantModel::query()->create($attributes);

        return $tenant->toArray();
    }

    public function updateById(string $id, array $attributes): ?array
    {
        $tenant = TenantModel::query()->find($id);
        if (! $tenant) {
            return null;
        }

        $tenant->fill($attributes);
        $tenant->save();

        return $tenant->toArray();
    }
}
