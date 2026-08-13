<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\TenantCountryPolicyRepositoryInterface;

class ConfigTenantCountryPolicyRepository implements TenantCountryPolicyRepositoryInterface
{
    public function allowedCountryCodesForTenant(?string $tenantCode, ?string $tenantCountryCode = null): ?array
    {
        $tenantPolicy = $this->tenantPolicy($tenantCode);
        $defaultPolicy = config('tenant_country_policies.default', []);

        $policy = is_array($tenantPolicy) ? $tenantPolicy : $defaultPolicy;

        $allowedCountryCodes = $this->normalizeCountryCodes($policy['allowedCountryCodes'] ?? []);
        $includeTenantCountry = (bool) ($policy['includeTenantCountry'] ?? false);
        $normalizedTenantCountryCode = $this->normalizeCountryCode($tenantCountryCode);

        if ($includeTenantCountry && $normalizedTenantCountryCode !== null) {
            $allowedCountryCodes[] = $normalizedTenantCountryCode;
        }

        $allowedCountryCodes = array_values(array_unique($allowedCountryCodes));

        return $allowedCountryCodes !== [] ? $allowedCountryCodes : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenantPolicy(?string $tenantCode): ?array
    {
        $normalizedTenantCode = $this->normalizeCountryCode($tenantCode);
        if ($normalizedTenantCode === null) {
            return null;
        }

        $tenants = config('tenant_country_policies.tenants', []);
        if (! is_array($tenants)) {
            return null;
        }

        $policy = $tenants[$normalizedTenantCode] ?? null;

        return is_array($policy) ? $policy : null;
    }

    /**
     * @param  mixed  $value
     * @return array<int, string>
     */
    private function normalizeCountryCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $code): ?string => $this->normalizeCountryCode(is_string($code) ? $code : null),
            $value
        )));
    }

    private function normalizeCountryCode(?string $value): ?string
    {
        $normalized = strtoupper(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
