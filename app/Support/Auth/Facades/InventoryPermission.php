<?php

namespace App\Support\Auth\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool hasPermissionInDepartment(\App\Models\User $user, string $permission, ?\App\Modules\Department\Infrastructure\Models\DepartmentModel $targetDepartment = null, ?\App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel $warehouse = null)
 * @method static array getPermissionsInDepartment(\App\Models\User $user, \App\Modules\Department\Infrastructure\Models\DepartmentModel $department)
 *
 * @see \App\Support\Auth\DepartmentScopedPermissionResolver
 */
class InventoryPermission extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor(): string
    {
        return 'inventory.permission_resolver';
    }
}
