<?php

namespace App\Modules\Platform\Application\UseCases;

class GetEffectiveFeatureFlagUseCase
{
    public function __construct(private readonly ListEffectiveFeatureFlagsUseCase $listEffectiveFeatureFlagsUseCase) {}

    public function execute(string $flagName): ?array
    {
        $flagName = trim($flagName);
        if ($flagName === '') {
            return null;
        }

        $result = $this->listEffectiveFeatureFlagsUseCase->execute([]);

        $match = collect($result['data'] ?? [])
            ->first(static fn (array $item): bool => ($item['name'] ?? null) === $flagName);

        if (! is_array($match)) {
            return null;
        }

        return [
            'data' => $match,
            'meta' => [
                'scope' => $result['meta']['scope'] ?? null,
                'precedence' => $result['meta']['precedence'] ?? ['global', 'country', 'tenant', 'facility'],
            ],
        ];
    }
}
