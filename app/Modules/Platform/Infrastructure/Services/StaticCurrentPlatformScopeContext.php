<?php

namespace App\Modules\Platform\Infrastructure\Services;

use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;

/**
 * A fixed, non-request-derived scope context — for console/CLI code (audits,
 * scheduled checks) that needs to evaluate scope-dependent logic for a
 * specific tenant/facility outside of any HTTP request. Bind this into the
 * container in place of RequestCurrentPlatformScopeContext (the only other
 * implementation, which reads from the current request's attributes and has
 * nothing to read outside a request) before resolving a scope-dependent
 * service, so that service sees exactly the same scope a real request for
 * that tenant/facility would — reusing production scope-resolution logic
 * rather than a second implementation of it.
 */
class StaticCurrentPlatformScopeContext implements CurrentPlatformScopeContextInterface
{
    /**
     * @param  array<string, mixed>|null  $tenant
     * @param  array<string, mixed>|null  $facility
     */
    public function __construct(
        private readonly ?array $tenant,
        private readonly ?array $facility,
    ) {}

    public function toArray(): array
    {
        return [
            'tenant' => $this->tenant,
            'facility' => $this->facility,
            'resolvedFrom' => 'static',
            'headers' => ['tenantCode' => null, 'facilityCode' => null],
            'userAccess' => ['accessibleFacilityCount' => 0, 'facilities' => []],
        ];
    }

    public function tenant(): ?array
    {
        return $this->tenant;
    }

    public function facility(): ?array
    {
        return $this->facility;
    }

    public function tenantId(): ?string
    {
        $id = $this->tenant['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function facilityId(): ?string
    {
        $id = $this->facility['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function resolvedFrom(): string
    {
        return 'static';
    }

    public function hasTenant(): bool
    {
        return $this->tenantId() !== null;
    }

    public function hasFacility(): bool
    {
        return $this->facilityId() !== null;
    }
}
