<?php

use App\Models\User;
use App\Modules\InventoryProcurement\Application\Services\DepartmentRequisitionScopeResolver;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function seedTestPlatformScope(User $user): array
{
    $tenantId = (string) Str::uuid();
    $facilityId = (string) Str::uuid();

    DB::table('tenants')->insert([
        'id' => $tenantId,
        'code' => 'TEST-DEPT-SCOPE',
        'name' => 'Department Scope Test Tenant',
        'country_code' => 'TZ',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facilities')->insert([
        'id' => $facilityId,
        'tenant_id' => $tenantId,
        'code' => 'TEST-FACILITY',
        'name' => 'Test Facility',
        'facility_type' => 'hospital',
        'timezone' => 'Africa/Dar_es_Salaam',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('facility_user')->insert([
        'facility_id' => $facilityId,
        'user_id' => $user->id,
        'role' => 'general_user',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['tenantId' => $tenantId, 'facilityId' => $facilityId];
}

function seedDepartments(string $facilityId): array
{
    $pharmacy = DepartmentModel::create([
        'facility_id' => $facilityId,
        'code' => 'PHM',
        'name' => 'Pharmacy',
        'service_type' => 'dispensing',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);

    $laboratory = DepartmentModel::create([
        'facility_id' => $facilityId,
        'code' => 'LAB',
        'name' => 'Laboratory',
        'service_type' => 'diagnostics',
        'is_patient_facing' => true,
        'is_appointmentable' => false,
        'status' => 'active',
    ]);

    return ['pharmacy' => $pharmacy, 'laboratory' => $laboratory];
}

test('user_without_staff_profile_has_no_locked_department', function () {
    $user = User::factory()->create();
    seedTestPlatformScope($user);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $context = $resolver->contextForUser($user);

    expect($context['canSelectAnyDepartment'])->toBeFalse();
    expect($context['lockedDepartment'])->toBeNull();
    expect($context['staffDepartmentName'])->toBeNull();
    expect($context['staffDepartmentId'])->toBeNull();
});

test('user_with_staff_profile_department_is_locked_to_that_department', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    $departments = seedDepartments($scope['facilityId']);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-001',
        'department' => 'Pharmacy',
        'department_id' => $departments['pharmacy']->id,
        'job_title' => 'Pharmacist',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $context = $resolver->contextForUser($user);

    expect($context['canSelectAnyDepartment'])->toBeFalse();
    expect($context['lockedDepartment'])->not->toBeNull();
    expect($context['lockedDepartment']['id'])->toBe($departments['pharmacy']->id);
    expect($context['lockedDepartment']['name'])->toBe('Pharmacy');
    expect($context['staffDepartmentName'])->toBe('Pharmacy');
    expect($context['staffDepartmentId'])->toBe($departments['pharmacy']->id);
});

test('user_without_department_id_falls_back_to_department_text', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    $departments = seedDepartments($scope['facilityId']);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-002',
        'department' => 'Laboratory',
        'department_id' => null,
        'job_title' => 'Lab Technician',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $context = $resolver->contextForUser($user);

    expect($context['canSelectAnyDepartment'])->toBeFalse();
    expect($context['lockedDepartment'])->not->toBeNull();
    expect($context['lockedDepartment']['id'])->toBe($departments['laboratory']->id);
    expect($context['lockedDepartment']['name'])->toBe('Laboratory');
    expect($context['staffDepartmentName'])->toBe('Laboratory');
});

test('system_admin_can_select_any_department', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    seedDepartments($scope['facilityId']);

    // Grant system admin role
    DB::table('facility_user')
        ->where('user_id', $user->id)
        ->update(['role' => 'super_admin']);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $context = $resolver->contextForUser($user);

    expect($context['canSelectAnyDepartment'])->toBeTrue();
    expect($context['lockedDepartment'])->toBeNull();
});

test('user_with_inventory_management_permission_can_select_any_department', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    seedDepartments($scope['facilityId']);

    $user->givePermissionTo('inventory.procurement.manage-items');

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $context = $resolver->contextForUser($user);

    expect($context['canSelectAnyDepartment'])->toBeTrue();
});

test('pharmacy_department_restricts_to_pharmacy_categories', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    $departments = seedDepartments($scope['facilityId']);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-003',
        'department' => 'Pharmacy',
        'department_id' => $departments['pharmacy']->id,
        'job_title' => 'Pharmacist',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $allowedCategories = $resolver->allowedCategoriesForDepartmentId($departments['pharmacy']->id);

    expect($allowedCategories)->not->toBeNull();
    expect($allowedCategories)->toContain('pharmaceutical');
    expect($allowedCategories)->toContain('medical_consumable');
    expect($allowedCategories)->toContain('ppe');
});

test('laboratory_department_restricts_to_laboratory_categories', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    $departments = seedDepartments($scope['facilityId']);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-004',
        'department' => 'Laboratory',
        'department_id' => $departments['laboratory']->id,
        'job_title' => 'Lab Technician',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $allowedCategories = $resolver->allowedCategoriesForDepartmentId($departments['laboratory']->id);

    expect($allowedCategories)->not->toBeNull();
    expect($allowedCategories)->toContain('laboratory');
    expect($allowedCategories)->toContain('medical_consumable');
    expect($allowedCategories)->toContain('ppe');
});

test('resolve_for_store_payload_uses_locked_department_when_not_admin', function () {
    $user = User::factory()->create();
    $scope = seedTestPlatformScope($user);
    $departments = seedDepartments($scope['facilityId']);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-005',
        'department' => 'Pharmacy',
        'department_id' => $departments['pharmacy']->id,
        'job_title' => 'Pharmacist',
        'employment_type' => 'full_time',
        'status' => 'active',
    ]);

    $resolver = app(DepartmentRequisitionScopeResolver::class);
    $resolved = $resolver->resolveForStorePayload([], $user);

    expect($resolved['id'])->toBe($departments['pharmacy']->id);
    expect($resolved['name'])->toBe('Pharmacy');
});
