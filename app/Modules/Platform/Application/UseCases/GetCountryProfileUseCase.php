<?php

namespace App\Modules\Platform\Application\UseCases;

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantCountryPolicyRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

class GetCountryProfileUseCase
{
    public function __construct(
        private readonly CountryProfileRepositoryInterface $countryProfileRepository,
        private readonly TenantCountryPolicyRepositoryInterface $tenantCountryPolicyRepository,
        private readonly CurrentPlatformScopeContextInterface $scopeContext,
    ) {}

    public function execute(?string $requestedCode = null): ?array
    {
        $activeCode = $this->countryProfileRepository->getActiveCode();
        $tenant = $this->scopeContext->tenant();
        $tenantCode = is_string($tenant['code'] ?? null) ? (string) $tenant['code'] : null;
        $tenantCountryCode = is_string($tenant['countryCode'] ?? null) ? (string) $tenant['countryCode'] : null;
        $requestedNormalizedCode = $requestedCode ? strtoupper(trim($requestedCode)) : null;

        $availableProfiles = array_values(array_filter(
            $this->countryProfileRepository->all(),
            static fn (mixed $candidate): bool => is_array($candidate)
        ));
        $catalogProfiles = $availableProfiles;

        $allowedCountryCodes = $this->tenantCountryPolicyRepository->allowedCountryCodesForTenant(
            $tenantCode,
            $tenantCountryCode,
        );

        if ($allowedCountryCodes !== null) {
            $availableProfiles = array_values(array_filter(
                $availableProfiles,
                static function (array $profile) use ($allowedCountryCodes): bool {
                    $code = strtoupper(trim((string) ($profile['code'] ?? '')));

                    return $code !== '' && in_array($code, $allowedCountryCodes, true);
                }
            ));
        }

        $effectiveCode = $requestedNormalizedCode ?? $this->resolveDefaultCode(
            activeCode: $activeCode,
            tenantCountryCode: $tenantCountryCode,
            availableProfiles: $availableProfiles,
        );

        if ($effectiveCode === null) {
            return null;
        }

        if ($requestedNormalizedCode !== null && $allowedCountryCodes !== null && ! in_array($effectiveCode, $allowedCountryCodes, true)) {
            return null;
        }

        $profile = $this->countryProfileRepository->findByCode($effectiveCode);
        if (! $profile) {
            return null;
        }

        return [
            'activeCode' => $activeCode,
            'requestedCode' => $requestedNormalizedCode,
            'profile' => $profile,
            'availableProfiles' => $availableProfiles,
            'catalogProfiles' => $catalogProfiles,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableProfiles
     */
    private function resolveDefaultCode(
        string $activeCode,
        ?string $tenantCountryCode,
        array $availableProfiles,
    ): ?string {
        $normalizedTenantCountryCode = strtoupper(trim((string) $tenantCountryCode));
        if ($normalizedTenantCountryCode !== '' && $this->profileCodeExists($normalizedTenantCountryCode, $availableProfiles)) {
            return $normalizedTenantCountryCode;
        }

        if ($this->profileCodeExists($activeCode, $availableProfiles)) {
            return $activeCode;
        }

        foreach ($availableProfiles as $profile) {
            $code = strtoupper(trim((string) ($profile['code'] ?? '')));
            if ($code !== '') {
                return $code;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableProfiles
     */
    private function profileCodeExists(string $code, array $availableProfiles): bool
    {
        $normalizedCode = strtoupper(trim($code));
        if ($normalizedCode === '') {
            return false;
        }

        foreach ($availableProfiles as $profile) {
            if (strtoupper(trim((string) ($profile['code'] ?? ''))) === $normalizedCode) {
                return true;
            }
        }

        return false;
    }
}
