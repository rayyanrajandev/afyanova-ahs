<?php

namespace App\Modules\Platform\Infrastructure\Repositories;

use App\Modules\Platform\Domain\Repositories\TenantCountryPolicyRepositoryInterface;
use App\Modules\Platform\Domain\Repositories\TenantRepositoryInterface;

class DatabaseTenantCountryPolicyRepository implements TenantCountryPolicyRepositoryInterface
{
    public function __construct(
        private readonly TenantRepositoryInterface $tenantRepository,
        private readonly ConfigTenantCountryPolicyRepository $configTenantCountryPolicyRepository,
    ) {}

    public function allowedCountryCodesForTenant(?string $tenantCode, ?string $tenantCountryCode = null): ?array
    {
        $databasePolicy = $this->databasePolicy($tenantCode);
        if ($databasePolicy !== null) {
            return $databasePolicy;
        }

        return $this->configTenantCountryPolicyRepository->allowedCountryCodesForTenant(
            $tenantCode,
            $tenantCountryCode,
        );
    }

    /**
     * @return array<int, string>|null
     */
    private function databasePolicy(?string $tenantCode): ?array
    {
        $normalizedTenantCode = $this->normalizeCountryCode($tenantCode);
        if ($normalizedTenantCode === null) {
            return null;
        }

        $tenant = $this->tenantRepository->findByCode($normalizedTenantCode);
        if (! is_array($tenant) || ! array_key_exists('allowed_country_codes', $tenant)) {
            return null;
        }

        if ($tenant['allowed_country_codes'] === null) {
            return null;
        }

        $allowedCountryCodes = $this->normalizeCountryCodes($tenant['allowed_country_codes']);

        return array_values(array_unique($allowedCountryCodes));
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
