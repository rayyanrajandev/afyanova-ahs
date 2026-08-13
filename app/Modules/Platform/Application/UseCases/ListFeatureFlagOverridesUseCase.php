<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;

class ListFeatureFlagOverridesUseCase
{
    public function __construct(private readonly FeatureFlagOverrideRepositoryInterface $repository) {}

    public function execute(array $filters): array
    {
        $flagName = isset($filters['flagName']) ? trim((string) $filters['flagName']) : null;
        $flagName = $flagName === '' ? null : $flagName;

        $scopeType = isset($filters['scopeType']) ? strtolower(trim((string) $filters['scopeType'])) : null;
        $scopeType = $scopeType === '' ? null : $scopeType;

        $scopeKey = isset($filters['scopeKey']) ? trim((string) $filters['scopeKey']) : null;
        if ($scopeType === 'country' && $scopeKey !== null) {
            $scopeKey = strtoupper($scopeKey);
        }
        $scopeKey = $scopeKey === '' ? null : $scopeKey;

        $rows = $this->repository->list([
            'flagName' => $flagName,
            'scopeType' => $scopeType,
            'scopeKey' => $scopeKey,
        ]);

        $data = array_map(static function (array $row): array {
            return [
                'id' => $row['id'] ?? null,
                'flagName' => $row['flag_name'] ?? null,
                'scopeType' => $row['scope_type'] ?? null,
                'scopeKey' => $row['scope_key'] ?? null,
                'enabled' => (bool) ($row['enabled'] ?? false),
                'reason' => $row['reason'] ?? null,
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
                'createdAt' => $row['created_at'] ?? null,
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }, $rows);

        return [
            'data' => $data,
            'meta' => [
                'total' => count($data),
                'filters' => [
                    'flagName' => $flagName,
                    'scopeType' => $scopeType,
                    'scopeKey' => $scopeKey,
                ],
            ],
        ];
    }
}
