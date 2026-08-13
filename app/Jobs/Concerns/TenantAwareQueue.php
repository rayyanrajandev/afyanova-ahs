<?php

namespace App\Jobs\Concerns;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

trait TenantAwareQueue
{
    public ?string $tenantId = null;

    public function initializeTenantAwareQueue(): void
    {
        $context = app(CurrentPlatformScopeContextInterface::class);

        if ($context->hasTenant()) {
            $this->tenantId = $context->tenantId();
        }
    }

    public function viaQueue(): string
    {
        if ($this->tenantId !== null) {
            return "tenant-{$this->tenantId}-default";
        }

        return 'default';
    }

    public function tags(): array
    {
        $tags = parent::tags();

        if ($this->tenantId !== null) {
            $tags[] = "tenant:{$this->tenantId}";
        }

        return $tags;
    }
}