<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;
use App\Modules\Platform\Infrastructure\Models\FeatureFlagOverrideModel;
use Illuminate\Database\Eloquent\Builder;

class EloquentFeatureFlagOverrideRepository implements FeatureFlagOverrideRepositoryInterface
{
    public function list(array $filters = []): array
    {
        $query = FeatureFlagOverrideModel::query();

        $flagName = isset($filters['flagName']) ? trim((string) $filters['flagName']) : null;
        if ($flagName !== null && $flagName !== '') {
            $query->where('flag_name', $flagName);
        }

        $scopeType = isset($filters['scopeType']) ? strtolower(trim((string) $filters['scopeType'])) : null;
        if ($scopeType !== null && $scopeType !== '') {
            $query->where('scope_type', $scopeType);
        }

        $scopeKey = isset($filters['scopeKey']) ? trim((string) $filters['scopeKey']) : null;
        if ($scopeKey !== null && $scopeKey !== '') {
            $query->where('scope_key', $scopeKey);
        }

        return $query
            ->orderBy('flag_name')
            ->orderBy('scope_type')
            ->orderBy('scope_key')
            ->get()
            ->map(static fn (FeatureFlagOverrideModel $model): array => $model->toArray())
            ->all();
    }

    public function listApplicable(array $flagNames, array $scopes): array
    {
        $flagNames = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $flagNames
        ), static fn (string $name): bool => $name !== ''));

        if ($flagNames === [] || $scopes === []) {
            return [];
        }

        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            $scopeType = strtolower(trim((string) ($scope['scope_type'] ?? '')));
            $scopeKey = trim((string) ($scope['scope_key'] ?? ''));

            if ($scopeType === '' || $scopeKey === '') {
                continue;
            }

            $normalizedScopes[] = [
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
            ];
        }

        if ($normalizedScopes === []) {
            return [];
        }

        $query = FeatureFlagOverrideModel::query()
            ->whereIn('flag_name', $flagNames)
            ->where(function (Builder $builder) use ($normalizedScopes): void {
                foreach ($normalizedScopes as $scope) {
                    $builder->orWhere(function (Builder $scopeQuery) use ($scope): void {
                        $scopeQuery
                            ->where('scope_type', $scope['scope_type'])
                            ->where('scope_key', $scope['scope_key']);
                    });
                }
            });

        return $query
            ->orderBy('flag_name')
            ->orderBy('scope_type')
            ->orderBy('scope_key')
            ->get()
            ->map(static fn (FeatureFlagOverrideModel $model): array => $model->toArray())
            ->all();
    }

    public function findById(string $id): ?array
    {
        $model = FeatureFlagOverrideModel::query()->find($id);

        return $model?->toArray();
    }

    public function findByIdentity(string $flagName, string $scopeType, string $scopeKey): ?array
    {
        $model = FeatureFlagOverrideModel::query()
            ->where('flag_name', trim($flagName))
            ->where('scope_type', strtolower(trim($scopeType)))
            ->where('scope_key', trim($scopeKey))
            ->first();

        return $model?->toArray();
    }

    public function create(array $payload): array
    {
        $model = FeatureFlagOverrideModel::query()->create($payload);

        return $model->toArray();
    }

    public function updateById(string $id, array $payload): ?array
    {
        $model = FeatureFlagOverrideModel::query()->find($id);
        if ($model === null) {
            return null;
        }

        $model->fill($payload);
        $model->save();

        return $model->fresh()?->toArray() ?? $model->toArray();
    }

    public function deleteById(string $id): bool
    {
        $deleted = FeatureFlagOverrideModel::query()
            ->whereKey($id)
            ->delete();

        return $deleted > 0;
    }
}
