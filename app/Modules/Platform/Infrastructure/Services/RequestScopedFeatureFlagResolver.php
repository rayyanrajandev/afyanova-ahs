<?php

namespace App\Modules\Platform\Infrastructure\Services;

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;

class RequestScopedFeatureFlagResolver implements FeatureFlagResolverInterface
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $rawFlags = null;

    /**
     * @var array<string, bool>
     */
    private array $enabledCache = [];

    public function __construct(
        private readonly FeatureFlagRepositoryInterface $featureFlagRepository,
        private readonly FeatureFlagOverrideRepositoryInterface $featureFlagOverrideRepository,
        private readonly CountryProfileRepositoryInterface $countryProfileRepository,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function isEnabled(string $flagName, bool $default = false): bool
    {
        $flagName = trim($flagName);
        if ($flagName === '') {
            return $default;
        }

        if (array_key_exists($flagName, $this->enabledCache)) {
            return $this->enabledCache[$flagName];
        }

        $rawFlags = $this->rawFlags();
        $directEnabled = config('feature_flags.flags.'.$flagName.'.enabled');
        if ($directEnabled !== null) {
            $enabled = (bool) $directEnabled;
        } else {
            if (! array_key_exists($flagName, $rawFlags)) {
                return $default;
            }

            $payload = $rawFlags[$flagName];
            $enabled = is_array($payload)
                ? (bool) ($payload['enabled'] ?? false)
                : (bool) $payload;
        }

        $scope = $this->scopeContext->toArray();
        $tenant = is_array($scope['tenant'] ?? null) ? $scope['tenant'] : null;
        $countryCode = strtoupper((string) ($tenant['countryCode'] ?? $this->countryProfileRepository->getActiveCode()));
        $tenantId = $this->scopeContext->tenantId();
        $facilityId = $this->scopeContext->facilityId();

        $scopeCandidates = [];
        if ($countryCode !== '') {
            $scopeCandidates[] = ['scope_type' => 'country', 'scope_key' => $countryCode];
        }
        if ($tenantId !== null) {
            $scopeCandidates[] = ['scope_type' => 'tenant', 'scope_key' => $tenantId];
        }
        if ($facilityId !== null) {
            $scopeCandidates[] = ['scope_type' => 'facility', 'scope_key' => $facilityId];
        }

        if ($scopeCandidates !== []) {
            $overrideRows = $this->featureFlagOverrideRepository->listApplicable([$flagName], $scopeCandidates);
            $overrideMap = [];
            foreach ($overrideRows as $row) {
                $scopeType = (string) ($row['scope_type'] ?? '');
                if ($scopeType === '') {
                    continue;
                }

                $overrideMap[$scopeType] = (bool) ($row['enabled'] ?? false);
            }

            foreach (['country', 'tenant', 'facility'] as $scopeType) {
                if (array_key_exists($scopeType, $overrideMap)) {
                    $enabled = $overrideMap[$scopeType];
                }
            }
        }

        return $this->enabledCache[$flagName] = $enabled;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawFlags(): array
    {
        if ($this->rawFlags !== null) {
            return $this->rawFlags;
        }

        $flags = $this->featureFlagRepository->all();

        return $this->rawFlags = is_array($flags) ? $flags : [];
    }
}
