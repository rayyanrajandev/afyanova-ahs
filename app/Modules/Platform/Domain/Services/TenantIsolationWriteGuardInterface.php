<?php

namespace App\Modules\Platform\Domain\Services;

interface TenantIsolationWriteGuardInterface
{
    public function assertTenantScopeForWrite(): void;
}
