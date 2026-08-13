<?php

namespace App\Modules\Platform\Domain\Repositories;

interface TenantCountryPolicyRepositoryInterface
{
    /**
     * @return array<int, string>|null
     */
    public function allowedCountryCodesForTenant(?string $tenantCode, ?string $tenantCountryCode = null): ?array;
}
