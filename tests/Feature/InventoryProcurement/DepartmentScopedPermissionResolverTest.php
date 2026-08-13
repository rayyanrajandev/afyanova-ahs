<?php

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use App\Support\Auth\DepartmentScopedPermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->resolver = new DepartmentScopedPermissionResolver();

    $this->tenant = TenantModel::create([
        'code' => 'TEST-TENANT',
        'name' => 'Test Hospital',
        'country_code' => 'TZ',
    ]);

    $this->facility = FacilityModel::create([
        'tenant_id' => $this->tenant->id,
        'code' => 'TEST-FACILITY',
        'name' => 'Test Facility',
        'facility_type' => 'Hospital',
        'status' => 'active',
    ]);

    $this->labDept = DepartmentModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'code' => 'LAB',
        'name' => 'Laboratory',
        'status' => 'active',
    ]);

    $this->pharmaDept = DepartmentModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'code' => 'PHARMA',
        'name' => 'Pharmacy',
        'status' => 'active',
    ]);

    $this->user = User::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'John Doe',
        'email' => 'john@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $this->user->id,
        'department_id' => $this->labDept->id,
        'tenant_id' => $this->tenant->id,
        'employee_number' => 'EMP001',
        'department' => $this->labDept->name,
        'job_title' => 'Lab Technician',
        'employment_type' => 'full_time',
    ]);
});

it('allows access to own department inventory', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $this->user->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->labDept
    ))->toBeTrue();
});

it('denies access to other department inventory', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $this->user->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->pharmaDept
    ))->toBeFalse();
});

it('denies access for expired role', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
        'effective_from' => now()->subMonths(2),
        'effective_until' => now()->subDay(),
    ]);

    $this->user->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->labDept
    ))->toBeFalse();
});

it('denies access for revoked role', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
        'revoked_at' => now(),
        'revocation_reason' => 'Employee terminated',
    ]);

    $this->user->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->labDept
    ))->toBeFalse();
});

it('enforces access level hierarchy', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'request',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $this->user->roles()->attach($role->id);

    $resolver = $this->resolver;

    expect($resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->labDept
    ))->toBeTrue();

    expect($resolver->hasPermissionInDepartment(
        $this->user, 'inventory.create-requisition-own-department', $this->labDept
    ))->toBeTrue();

    expect($resolver->hasPermissionInDepartment(
        $this->user, 'inventory.approve-requisition-own-department', $this->labDept
    ))->toBeFalse();
});

it('allows access to related departments', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
        'code' => 'LAB.MANAGER',
        'name' => 'Lab Manager',
        'access_level' => 'manage',
        'scope_type' => 'related_departments',
        'related_department_ids' => [$this->pharmaDept->id],
        'status' => 'active',
    ]);

    $this->user->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $this->user, 'inventory.view-own-items', $this->pharmaDept
    ))->toBeTrue();
});

it('denies access when user has no department assignment', function (): void {
    $newUser = User::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Jane Doe',
        'email' => 'jane@test.com',
        'password' => bcrypt('password'),
    ]);

    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $newUser->roles()->attach($role->id);

    expect($this->resolver->hasPermissionInDepartment(
        $newUser, 'inventory.view-own-items', $this->labDept
    ))->toBeFalse();
});

it('returns correct permissions for access level', function (): void {
    $role = RoleModel::create([
        'tenant_id' => $this->tenant->id,
        'facility_id' => $this->facility->id,
        'department_id' => $this->labDept->id,
            'code' => 'LAB.STAFF',
        'name' => 'Lab Technician',
        'access_level' => 'request',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $this->user->roles()->attach($role->id);

    $permissions = $this->resolver->getPermissionsInDepartment(
        $this->user, $this->labDept
    );

    expect($permissions)->toContain('inventory.view-own-items');
    expect($permissions)->toContain('inventory.create-requisition-own-department');
    expect($permissions)->not->toContain('inventory.approve-requisition-own-department');
});
