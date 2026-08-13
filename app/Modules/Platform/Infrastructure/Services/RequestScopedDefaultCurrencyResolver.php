<?php

namespace App\Modules\Platform\Infrastructure\Services;

use App\Modules\Platform\Domain\Repositories\CountryProfileRepositoryInterface;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\DefaultCurrencyResolverInterface;

class RequestScopedDefaultCurrencyResolver implements DefaultCurrencyResolverInterface
{
    private const FALLBACK_CURRENCY_CODE = 'TZS';

    public function __construct(
        private readonly CountryProfileRepositoryInterface $countryProfileRepository,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    public function resolve(): string
    {
        $tenant = $this->platformScopeContext->tenant();
        $tenantCountryCode = strtoupper(trim((string) ($tenant['countryCode'] ?? '')));
        $activeCountryCode = strtoupper(trim($this->countryProfileRepository->getActiveCode()));

        $candidateCountryCodes = array_values(array_unique(array_filter([
            $tenantCountryCode !== '' ? $tenantCountryCode : null,
            $activeCountryCode !== '' ? $activeCountryCode : null,
        ])));

        foreach ($candidateCountryCodes as $countryCode) {
            $profile = $this->countryProfileRepository->findByCode($countryCode);
            $currencyCode = strtoupper(trim((string) ($profile['currencyCode'] ?? '')));

            if ($currencyCode !== '') {
                return $currencyCode;
            }
        }

        return self::FALLBACK_CURRENCY_CODE;
    }
}
