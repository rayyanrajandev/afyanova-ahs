<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;

class DepartmentScopedPermissionResolver
{
    /**
     * Get user's assigned department model from staff profile
     *
     * @param User $user
     * @return DepartmentModel|null
     */
    private function getUserDepartment(User $user): ?DepartmentModel
    {
        $staffProfile = $user->staffProfile;
        if (!$staffProfile || !$staffProfile->department_id) {
            return null;
        }

        return DepartmentModel::find($staffProfile->department_id);
    }

    /**
     * Check if user has permission within department scope
     *
     * @param User $user
     * @param string $permission
     * @param DepartmentModel|null $targetDepartment
     * @param InventoryWarehouseModel|null $warehouse
     * @return bool
     */
    public function hasPermissionInDepartment(
        User $user,
        string $permission,
        ?DepartmentModel $targetDepartment = null,
        ?InventoryWarehouseModel $warehouse = null
    ): bool {
        // Platform super admin bypass — only the true platform-wide super admin
        // gets department-scoped access for free. Facility admins (ADMIN.FACILITY)
        // must hold the actual permission via their role's grants, like anyone else.
        if ($user->isPlatformSuperAdminAccess()) {
            return true;
        }

        $userDepartment = $this->getUserDepartment($user);
        if (!$userDepartment) {
            return false;
        }

        if ($targetDepartment && $targetDepartment->id !== $userDepartment->id) {
            if (!$this->canAccessOtherDepartment($user, $targetDepartment)) {
                return false;
            }
            $departmentToCheck = $userDepartment;
        } else {
            $departmentToCheck = $userDepartment;
        }

        $activeRoles = $user->inventoryAccessRoles()
            ->where('department_id', $departmentToCheck->id)
            ->active()
            ->get();

        if ($activeRoles->isEmpty()) {
            return false;
        }

        foreach ($activeRoles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                if ($warehouse && !$this->canAccessWarehouse($role, $warehouse)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Check if user can access another department based on scope rules
     *
     * @param User $user
     * @param DepartmentModel $targetDepartment
     * @return bool
     */
    private function canAccessOtherDepartment(
        User $user,
        DepartmentModel $targetDepartment
    ): bool {
        $userDepartment = $this->getUserDepartment($user);
        if (!$userDepartment) {
            return false;
        }

        // Get user's inventory access roles with cross-department scope
        $accessRoles = $user->inventoryAccessRoles()
            ->whereIn('scope_type', ['related_departments', 'facility', 'cross_facility'])
            ->active()
            ->get();

        foreach ($accessRoles as $role) {
            if ($role->scope_type === 'related_departments') {
                // Check if target is in the allowed related departments list
                if (in_array($targetDepartment->id, $role->related_department_ids ?? [])) {
                    return true;
                }
            } elseif ($role->scope_type === 'facility') {
                // Same facility = allowed
                if ($userDepartment->facility_id === $targetDepartment->facility_id) {
                    return true;
                }
            } elseif ($role->scope_type === 'cross_facility') {
                // Super admin bypass: cross-facility access
                return true;
            }
        }

        return false;
    }

    /**
     * Check if role has specific permission based on access level
     *
     * @param RoleModel $role
     * @param string $permission
     * @return bool
     */
    private function roleHasPermission(RoleModel $role, string $permission): bool
    {
        return $role->permissions()->where('name', $permission)->exists();
    }

    /**
     * Check if role can access specific warehouse
     *
     * @param RoleModel $role
     * @param InventoryWarehouseModel $warehouse
     * @return bool
     */
    private function canAccessWarehouse(
        RoleModel $role,
        InventoryWarehouseModel $warehouse
    ): bool {
        // Warehouse must belong to the same department as the role
        return $warehouse->department_id === $role->department_id;
    }

    /**
     * Get all permissions for user in a department
     *
     * @param User $user
     * @param DepartmentModel $department
     * @return array
     */
    public function getPermissionsInDepartment(
        User $user,
        DepartmentModel $department
    ): array {
        $permissions = [];

        $activeRoles = $user->inventoryAccessRoles()
            ->where('department_id', $department->id)
            ->active()
            ->get();

        foreach ($activeRoles as $role) {
            $rolePerms = $role->permissions()->pluck('name')->toArray();
            $permissions = array_unique(array_merge($permissions, $rolePerms));
        }

        return array_values($permissions);
    }
}
