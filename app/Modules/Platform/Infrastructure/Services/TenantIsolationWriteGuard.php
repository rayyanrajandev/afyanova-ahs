<?php

namespace App\Modules\Platform\Infrastructure\Services;

use App\Modules\Platform\Application\Exceptions\TenantScopeRequiredForIsolationException;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Modules\Platform\Domain\Services\FeatureFlagResolverInterface;
use App\Modules\Platform\Domain\Services\TenantIsolationWriteGuardInterface;

class TenantIsolationWriteGuard implements TenantIsolationWriteGuardInterface
{
    public function __construct(
        private readonly FeatureFlagResolverInterface $featureFlagResolver,
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
    ) {}

    public function assertTenantScopeForWrite(): void
    {
        if (! $this->featureFlagResolver->isEnabled('platform.multi_tenant_isolation')) {
            return;
        }

        if ($this->platformScopeContext->hasTenant()) {
            return;
        }

        throw new TenantScopeRequiredForIsolationException();
    }
}
