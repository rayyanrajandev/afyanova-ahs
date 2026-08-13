<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;

class ListFeatureFlagsUseCase
{
    public function __construct(private readonly FeatureFlagRepositoryInterface $featureFlagRepository) {}

    public function execute(array $filters): array
    {
        $prefix = isset($filters['prefix']) ? trim((string) $filters['prefix']) : null;
        $prefix = $prefix === '' ? null : $prefix;
        $normalizedPrefix = $prefix !== null ? strtolower($prefix) : null;

        $enabledOnly = filter_var($filters['enabledOnly'] ?? false, FILTER_VALIDATE_BOOL);

        $rawFlags = $this->featureFlagRepository->all();
        $normalized = [];

        foreach ($rawFlags as $name => $payload) {
            $flagName = (string) $name;
            if ($normalizedPrefix !== null && ! str_starts_with(strtolower($flagName), $normalizedPrefix)) {
                continue;
            }

            $details = is_array($payload) ? $payload : ['enabled' => (bool) $payload];
            $enabled = (bool) ($details['enabled'] ?? false);

            if ($enabledOnly && ! $enabled) {
                continue;
            }

            $normalized[] = [
                'name' => $flagName,
                'enabled' => $enabled,
                'owner' => $details['owner'] ?? null,
                'stage' => $details['stage'] ?? null,
                'description' => $details['description'] ?? null,
            ];
        }

        usort(
            $normalized,
            static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']),
        );

        return [
            'data' => $normalized,
            'meta' => [
                'total' => count($normalized),
                'prefix' => $prefix,
                'enabledOnly' => $enabledOnly,
            ],
        ];
    }
}
