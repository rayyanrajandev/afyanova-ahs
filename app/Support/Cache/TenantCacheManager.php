<?php

namespace App\Support\Cache;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use Illuminate\Cache\CacheManager;

class TenantCacheManager extends CacheManager
{
    protected function getPrefix(array $config): string
    {
        $context = app(CurrentPlatformScopeContextInterface::class);

        if ($context->hasTenant()) {
            $tenantId = $context->tenantId();

            return "tenant:{$tenantId}:";
        }

        return parent::getPrefix($config);
    }
}