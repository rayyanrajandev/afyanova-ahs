<?php

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Setup helpers for Phase 1 department RBAC integration tests
 */

function createInventoryAccessUser(array $scope, string $accessLevel = 'view', string $scopeType = 'own_department'): User
{
    $user = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'RBAC User',
        'email' => 'rbac-user-'.Str::random(6).'@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'department_id' => $scope['department']->id,
        'tenant_id' => $scope['tenant']->id,
        'employee_number' => 'EMP-'.Str::random(6),
        'department' => $scope['department']->name,
        'job_title' => 'Lab Technician',
        'employment_type' => 'full_time',
    ]);

    // Map user to facility (required by ResolvePlatformScopeContext middleware)
    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $user->id,
        'role' => 'inventory_user',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $role = RoleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'code' => 'RBAC.LAB.'.Str::random(4),
        'name' => 'RBAC Lab '.ucfirst($accessLevel),
        'access_level' => $accessLevel,
        'scope_type' => $scopeType,
        'status' => 'active',
    ]);

    $user->roles()->attach($role->id);

    return $user;
}

/**
 * Test: List inventory items for own department
 */
it('lists inventory items for own department', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'view');
    createInventoryItems($scope);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/inventory-department/items', scopeHeaders($scope));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [['id', 'item_code', 'item_name', 'category', 'unit', 'current_stock', 'status']],
        'meta' => ['current_page', 'last_page', 'total', 'per_page'],
    ]);
    expect($response->json('meta.total'))->toBe(3);
});

/**
 * Test: Unauthorized user cannot list items
 */
it('denies unauthenticated access to items', function (): void {
    $response = $this->getJson('/api/v1/inventory-department/items');
    $response->assertStatus(401);
});

/**
 * Test: User without department cannot list items
 */
it('denies access when user has no department assignment', function (): void {
    $scope = createRbacScope();
    $user = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'No Dept User',
        'email' => 'no-dept@test.com',
        'password' => bcrypt('password'),
    ]);

    // User needs facility access to pass middleware
    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $user->id,
        'role' => 'inventory_user',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/inventory-department/items', scopeHeaders($scope));

    $response->assertStatus(403);
    expect($response->json('error'))->toBe('User must be assigned to a department to view inventory');
});

/**
 * Test: Create requisition in own department
 */
it('creates requisition in own department', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'request');
    $items = createInventoryItems($scope, 2);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 10, 'unit' => 'box'],
                ['itemId' => (string) $items[1]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    $response->assertStatus(201);
    $response->assertJsonStructure([
        'data' => ['id', 'requisitionNumber', 'requestingDepartment', 'status', 'lines'],
    ]);
    expect($response->json('data.status'))->toBe('pending');
});

/**
 * Test: Cannot create requisition for other department
 */
it('denies requisition creation for other department', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'request');
    $items = createInventoryItems($scope, 1);

    // Create another department the user is NOT in
    $otherDept = DepartmentModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'code' => 'RBAC-DEPT-OTHER',
        'name' => 'Other Department',
        'status' => 'active',
    ]);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $otherDept->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 10, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    $response->assertStatus(403);
});

/**
 * Test: User without request permission cannot create requisition
 */
it('denies requisition creation without proper permission level', function (): void {
    $scope = createRbacScope();
    // User only has 'view' level, not 'request'
    $user = createInventoryAccessUser($scope, 'view');
    $items = createInventoryItems($scope, 1);

    $response = $this->actingAs($user)
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 10, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    $response->assertStatus(403);
});

/**
 * Test: List requisitions for own department
 */
it('lists requisitions for own department', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'request');
    $items = createInventoryItems($scope, 1);

    // Create a requisition first
    $this->actingAs($user)
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 10, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    // Now list
    $response = $this->actingAs($user)
        ->getJson('/api/v1/inventory-department/requisitions', scopeHeaders($scope));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => [['id', 'requisitionNumber', 'requestingDepartment', 'status']],
        'meta' => ['current_page', 'last_page', 'total', 'per_page'],
    ]);
    expect($response->json('meta.total'))->toBe(1);
});

/**
 * Test: Show single requisition
 */
it('shows single requisition', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'request');
    $items = createInventoryItems($scope, 1);

    $createResponse = $this->actingAs($user)
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 10, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    $requisitionId = $createResponse->json('data.id');

    $response = $this->actingAs($user)
        ->getJson("/api/v1/inventory-department/requisitions/{$requisitionId}", scopeHeaders($scope));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => ['id', 'requisitionNumber', 'requestingDepartment', 'status', 'lines'],
    ]);
});

/**
 * Test: Show single item by ID
 */
it('shows single inventory item by id', function (): void {
    $scope = createRbacScope();
    $user = createInventoryAccessUser($scope, 'view');
    $items = createInventoryItems($scope, 1);

    $response = $this->actingAs($user)
        ->getJson("/api/v1/inventory-department/items/{$items[0]->id}", scopeHeaders($scope));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'data' => ['id', 'item_code', 'item_name', 'category', 'unit', 'current_stock', 'status'],
    ]);
});

/**
 * Test: Expired role cannot access items
 */
it('denies access with expired role', function (): void {
    $scope = createRbacScope();
    $user = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'Expired Role User',
        'email' => 'expired-role@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $user->id,
        'department_id' => $scope['department']->id,
        'tenant_id' => $scope['tenant']->id,
        'employee_number' => 'EMP-EXPIRED',
        'department' => $scope['department']->name,
        'job_title' => 'Lab Technician',
        'employment_type' => 'full_time',
    ]);

    // Map user to facility
    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $user->id,
        'role' => 'inventory_user',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create expired role
    $role = RoleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'code' => 'RBAC.LAB.EXPIRED',
        'name' => 'RBAC Expired Role',
        'access_level' => 'view',
        'scope_type' => 'own_department',
        'status' => 'active',
        'effective_from' => now()->subMonths(2),
        'effective_until' => now()->subDay(),
    ]);

    $user->roles()->attach($role->id);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/inventory-department/items', scopeHeaders($scope));

    $response->assertStatus(403);
});
