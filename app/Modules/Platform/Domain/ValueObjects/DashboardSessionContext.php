<?php

namespace App\Modules\Platform\Domain\ValueObjects;

final class DashboardSessionContext
{
    /**
     * @param array<int, string> $roleCodesUpper
     * @param array<int, string> $permissionNames
     */
    public function __construct(
        public readonly array $roleCodesUpper,
        public readonly array $permissionNames,
        public readonly bool $isFacilitySuperAdmin,
        public readonly bool $isPlatformSuperAdmin,
    ) {}

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissionNames, true);
    }

    public function matchesAnyRole(array $configuredRoles): bool
    {
        foreach ($this->roleCodesUpper as $code) {
            if (in_array($code, $configuredRoles, true)) {
                return true;
            }
        }

        return false;
    }
}
