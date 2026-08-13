<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagOverrideRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\FeatureFlagRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class ListEffectiveFeatureFlagsUseCase
{
    public function __construct(
        private readonly FeatureFlagRepositoryInterface $featureFlagRepository,
        private readonly FeatureFlagOverrideRepositoryInterface $featureFlagOverrideRepository,
        private readonly CountryProfileRepositoryInterface $countryProfileRepository,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function execute(array $filters): array
    {
        $prefix = isset($filters['prefix']) ? trim((string) $filters['prefix']) : null;
        $prefix = $prefix === '' ? null : $prefix;
        $enabledOnly = filter_var($filters['enabledOnly'] ?? false, FILTER_VALIDATE_BOOL);

        $scope = $this->scopeContext->toArray();
        $tenant = is_array($scope['tenant'] ?? null) ? $scope['tenant'] : null;
        $facility = is_array($scope['facility'] ?? null) ? $scope['facility'] : null;

        $countryCode = strtoupper((string) (
            $tenant['countryCode']
            ?? $this->countryProfileRepository->getActiveCode()
        ));
        $tenantId = $this->scopeContext->tenantId();
        $facilityId = $this->scopeContext->facilityId();

        $rawFlags = $this->featureFlagRepository->all();
        $baseFlags = [];

        foreach ($rawFlags as $name => $payload) {
            $flagName = (string) $name;
            if ($prefix !== null && ! str_starts_with($flagName, $prefix)) {
                continue;
            }

            $details = is_array($payload) ? $payload : ['enabled' => (bool) $payload];
            $baseFlags[$flagName] = [
                'name' => $flagName,
                'enabled' => (bool) ($details['enabled'] ?? false),
                'owner' => $details['owner'] ?? null,
                'stage' => $details['stage'] ?? null,
                'description' => $details['description'] ?? null,
            ];
        }

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

        $overrideRows = $this->featureFlagOverrideRepository->listApplicable(array_keys($baseFlags), $scopeCandidates);
        $overrideMap = [];
        foreach ($overrideRows as $row) {
            $flagName = (string) ($row['flag_name'] ?? '');
            $scopeType = (string) ($row['scope_type'] ?? '');
            if ($flagName === '' || $scopeType === '') {
                continue;
            }

            $overrideMap[$flagName][$scopeType] = $row;
        }

        $data = [];
        foreach ($baseFlags as $flagName => $baseFlag) {
            $effectiveEnabled = (bool) $baseFlag['enabled'];
            $source = 'global';
            $appliedOverride = null;
            $appliedOverrides = [];

            foreach (['country', 'tenant', 'facility'] as $scopeType) {
                $row = $overrideMap[$flagName][$scopeType] ?? null;
                if (! is_array($row)) {
                    continue;
                }

                $effectiveEnabled = (bool) ($row['enabled'] ?? false);
                $source = $scopeType;
                $appliedOverride = [
                    'id' => $row['id'] ?? null,
                    'scopeType' => $row['scope_type'] ?? null,
                    'scopeKey' => $row['scope_key'] ?? null,
                    'enabled' => (bool) ($row['enabled'] ?? false),
                    'reason' => $row['reason'] ?? null,
                ];
                $appliedOverrides[] = $appliedOverride;
            }

            if ($enabledOnly && ! $effectiveEnabled) {
                continue;
            }

            $data[] = [
                'name' => $flagName,
                'enabled' => $effectiveEnabled,
                'baseEnabled' => (bool) $baseFlag['enabled'],
                'owner' => $baseFlag['owner'],
                'stage' => $baseFlag['stage'],
                'description' => $baseFlag['description'],
                'resolution' => [
                    'source' => $source,
                    'countryCode' => $countryCode ?: null,
                    'tenantId' => $tenantId,
                    'facilityId' => $facilityId,
                    'resolvedFrom' => $this->scopeContext->resolvedFrom(),
                ],
                'appliedOverride' => $appliedOverride,
                'appliedOverrides' => $appliedOverrides,
            ];
        }

        usort(
            $data,
            static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']),
        );

        return [
            'data' => $data,
            'meta' => [
                'total' => count($data),
                'prefix' => $prefix,
                'enabledOnly' => $enabledOnly,
                'scope' => [
                    'countryCode' => $countryCode ?: null,
                    'tenantId' => $tenantId,
                    'facilityId' => $facilityId,
                    'resolvedFrom' => $this->scopeContext->resolvedFrom(),
                ],
                'precedence' => ['global', 'country', 'tenant', 'facility'],
            ],
        ];
    }
}
