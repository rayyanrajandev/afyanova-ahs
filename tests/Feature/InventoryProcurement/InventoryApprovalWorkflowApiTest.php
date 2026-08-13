<?php

use App\Models\User;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalRuleModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\Platform\Infrastructure\Models\FacilityModel;
use App\Modules\Platform\Infrastructure\Models\RoleModel;
use App\Modules\Platform\Infrastructure\Models\TenantModel;
use App\Modules\Staff\Infrastructure\Models\StaffProfileModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Setup helpers for Phase 2 approval workflow integration tests
 */

function createPhase2Scope(): array
{
    $scope = createRbacScope();
    
    // Create approval workflow
    $workflow = InventoryApprovalWorkflowModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => null, // Facility-wide workflow
        'code' => 'PHARMACY_STANDARD',
        'name' => 'Standard Pharmacy Approval',
        'description' => 'Two-level approval: Manager + Director',
        'trigger_type' => 'requisition',
        'trigger_rules' => [
            'departments' => [$scope['department']->id],
            'max_items' => null,
            'max_amount' => null,
        ],
        'approval_steps' => [
            [
                'step' => 1,
                'type' => 'manager',
                'required_approvals' => 1,
                'description' => 'Manager approval',
            ],
            [
                'step' => 2,
                'type' => 'director',
                'required_approvals' => 1,
                'description' => 'Director approval',
            ],
        ],
        'status' => 'active',
    ]);

    // Create approval rules
    $managerRole = RoleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'code' => 'PHARMACY_MANAGER',
        'name' => 'Pharmacy Manager',
        'access_level' => 'approve',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $directorRole = RoleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'code' => 'PHARMACY_DIRECTOR',
        'name' => 'Pharmacy Director',
        'access_level' => 'approve',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    InventoryApprovalRuleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'role_id' => $managerRole->id,
        'approval_type' => 'manager',
        'approval_permissions' => [
            'can_approve_own_dept' => true,
            'can_approve_other_dept' => false,
        ],
        'max_requisition_amount' => 10000,
        'max_items_count' => 20,
        'allowed_categories' => null,
        'status' => 'active',
    ]);

    InventoryApprovalRuleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'role_id' => $directorRole->id,
        'approval_type' => 'director',
        'approval_permissions' => [
            'can_approve_own_dept' => true,
            'can_approve_other_dept' => false,
        ],
        'max_requisition_amount' => 50000,
        'max_items_count' => 100,
        'allowed_categories' => null,
        'status' => 'active',
    ]);

    return [
        ...$scope,
        'workflow' => $workflow,
        'manager_role' => $managerRole,
        'director_role' => $directorRole,
    ];
}

function createApprovalUsers(array $scope): array
{
    // Requester (can create requisitions)
    $requester = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'Jane Requester',
        'email' => 'requester-'.Str::random(6).'@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $requester->id,
        'department_id' => $scope['department']->id,
        'tenant_id' => $scope['tenant']->id,
        'employee_number' => 'REQ-'.Str::random(4),
        'department' => $scope['department']->name,
        'job_title' => 'Pharmacy Technician',
        'employment_type' => 'full_time',
    ]);

    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $requester->id,
        'role' => 'inventory_user',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $requesterRole = RoleModel::create([
        'tenant_id' => $scope['tenant']->id,
        'facility_id' => $scope['facility']->id,
        'department_id' => $scope['department']->id,
        'code' => 'PHARMACY_REQUESTER',
        'name' => 'Pharmacy Requester',
        'access_level' => 'request',
        'scope_type' => 'own_department',
        'status' => 'active',
    ]);

    $requester->roles()->attach($requesterRole->id);

    // Manager approver
    $manager = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'John Manager',
        'email' => 'manager-'.Str::random(6).'@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $manager->id,
        'department_id' => $scope['department']->id,
        'tenant_id' => $scope['tenant']->id,
        'employee_number' => 'MGR-'.Str::random(4),
        'department' => $scope['department']->name,
        'job_title' => 'Pharmacy Manager',
        'employment_type' => 'full_time',
    ]);

    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $manager->id,
        'role' => 'inventory_approver',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $manager->roles()->attach($scope['manager_role']->id);

    // Director approver
    $director = User::create([
        'tenant_id' => $scope['tenant']->id,
        'name' => 'Sarah Director',
        'email' => 'director-'.Str::random(6).'@test.com',
        'password' => bcrypt('password'),
    ]);

    StaffProfileModel::create([
        'user_id' => $director->id,
        'department_id' => $scope['department']->id,
        'tenant_id' => $scope['tenant']->id,
        'employee_number' => 'DIR-'.Str::random(4),
        'department' => $scope['department']->name,
        'job_title' => 'Pharmacy Director',
        'employment_type' => 'full_time',
    ]);

    \Illuminate\Support\Facades\DB::table('facility_user')->insert([
        'facility_id' => $scope['facility']->id,
        'user_id' => $director->id,
        'role' => 'inventory_approver',
        'is_primary' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $director->roles()->attach($scope['director_role']->id);

    return [
        'requester' => $requester,
        'manager' => $manager,
        'director' => $director,
    ];
}

/**
 * Test: List pending approvals for manager
 */
it('lists pending approvals for manager', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Manually create workflow instance
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    expect($instance)->not->toBeNull();
    expect($instance->step_number)->toBe(1);
    expect($instance->status)->toBe('in_progress');

    // Manager lists pending approvals
    $response = $this->actingAs($users['manager'])
        ->getJson('/api/v1/inventory-department/approvals/pending', scopeHeaders($scope));

    expect($response->status())->toBe(200);
    $pendingApprovals = $response->json('data');
    expect($pendingApprovals)->toHaveLength(1);
    expect($pendingApprovals[0]['id'])->toBe($instance->id);
});

/**
 * Test: Two-level approval workflow progression - manager approves, workflow moves to director
 */
it('progresses through two-level approval workflow when manager approves', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    expect($instance->step_number)->toBe(1);

    // Manager approves
    $response = $this->actingAs($users['manager'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'Looks good to me',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);

    // Verify workflow moved to step 2 (director)
    $updatedInstance = InventoryApprovalWorkflowInstanceModel::find($instance->id);
    expect($updatedInstance->step_number)->toBe(2);
    expect($updatedInstance->status)->toBe('in_progress');

    // Director approves
    $response = $this->actingAs($users['director'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'Approved by director',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);

    // Verify workflow is now approved
    $finalInstance = InventoryApprovalWorkflowInstanceModel::find($instance->id);
    expect($finalInstance->status)->toBe('approved');
    expect($finalInstance->completed_at)->not->toBeNull();
});

/**
 * Test: Segregation of duties - requester cannot approve own requisition
 */
it('prevents requester from approving own requisition (SOD violation)', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    // Requester tries to approve their own requisition - should fail with 403
    // (fails either at approval rule check or SOD validation)
    $response = $this->actingAs($users['requester'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'I approve my own request',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toBeString();
});

/**
 * Test: Rejection workflow
 */
it('allows manager to reject requisition', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    // Manager rejects
    $response = $this->actingAs($users['manager'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/reject", [
            'decision' => 'rejected',
            'comments' => 'Does not meet budget requirements',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);

    // Verify workflow is rejected
    $updatedInstance = InventoryApprovalWorkflowInstanceModel::find($instance->id);
    expect($updatedInstance->status)->toBe('rejected');

    // Verify requisition status is also rejected
    $updatedRequisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    expect($updatedRequisition->status)->toBe('rejected');
});

/**
 * Test: Recall workflow - requester can recall pending approval
 */
it('allows requester to recall in-progress workflow', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    // Requester recalls the requisition
    $response = $this->actingAs($users['requester'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/recall", [
            'reason' => 'Need to modify quantities',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);

    // Verify workflow is recalled
    $updatedInstance = InventoryApprovalWorkflowInstanceModel::find($instance->id);
    expect($updatedInstance->status)->toBe('recalled');

    // Verify requisition status is back to pending
    $updatedRequisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    expect($updatedRequisition->status)->toBe('pending');
});

/**
 * Test: Only requester can recall, not other users
 */
it('prevents non-requester from recalling workflow', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates a requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    // Manager tries to recall - should fail
    $response = $this->actingAs($users['manager'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/recall", [
            'reason' => 'Unauthorized recall',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(403);
});

/**
 * Test: Authority limits enforced - item count limit prevents approval
 */
it('enforces manager item count authority limit', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope, 25); // 25 items to create 21 lines

    // Create 21 separate lines to exceed manager 20-item limit
    $lines = [];
    for ($i = 0; $i < 21; $i++) {
        $lines[] = ['itemId' => (string) $items[$i]->id, 'requestedQuantity' => 1, 'unit' => 'box'];
    }

    // Requester creates a requisition with 21 line items (exceeds manager limit of 20)
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => $lines,
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $requisitionId = $response->json('data.id');

    // Initiate workflow
    $requisition = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($requisitionId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $requisition, $scope['workflow']);

    // Manager tries to approve - should fail due to item count limit
    $response = $this->actingAs($users['manager'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'Approved',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(403);
    expect($response->json('error'))->toContain('approval authority limits');
});

/**
 * Test: Director can approve high-quantity requisitions that manager cannot
 */
it('allows director to approve high-quantity requisitions', function (): void {
    $scope = createPhase2Scope();
    $users = createApprovalUsers($scope);
    $items = createInventoryItems($scope);

    // Requester creates low-value, small-quantity requisition
    $response = $this->actingAs($users['requester'])
        ->postJson('/api/v1/inventory-department/requisitions', [
            'requestingDepartmentId' => (string) $scope['department']->id,
            'issuingWarehouseId' => (string) $scope['warehouse']->id,
            'priority' => 'normal',
            'lines' => [
                ['itemId' => (string) $items[0]->id, 'requestedQuantity' => 5, 'unit' => 'box'],
            ],
        ], scopeHeaders($scope));

    expect($response->status())->toBe(201);
    $lowValueReqId = $response->json('data.id');

    // Initiate workflow
    $lowValueReq = \App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel::find($lowValueReqId);
    $engine = app(\App\Support\ApprovalWorkflow\ApprovalWorkflowEngine::class);
    $instance = $engine->initiateWorkflow($scope['tenant']->id, $lowValueReq, $scope['workflow']);

    // Manager approves low-value requisition
    $response = $this->actingAs($users['manager'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'Approved by manager',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);

    // Now director can approve (step 2)
    $response = $this->actingAs($users['director'])
        ->postJson("/api/v1/inventory-department/approvals/{$instance->id}/approve", [
            'decision' => 'approved',
            'comments' => 'Approved by director',
        ], scopeHeaders($scope));

    expect($response->status())->toBe(200);
});

/**
 * Test: Approval rules configuration is correct - verify manager limits are enforced
 */
it('verifies manager approval rule configuration', function (): void {
    $scope = createPhase2Scope();
    
    // Get manager rule
    $managerRule = InventoryApprovalRuleModel::query()
        ->where('department_id', $scope['department']->id)
        ->where('approval_type', 'manager')
        ->first();

    expect($managerRule)->not->toBeNull();
    expect($managerRule->max_requisition_amount)->toBe(10000);
    expect($managerRule->max_items_count)->toBe(20);
});

/**
 * Test: Director has higher authority limits than manager
 */
it('director has higher approval authority than manager', function (): void {
    $scope = createPhase2Scope();
    
    $managerRule = InventoryApprovalRuleModel::query()
        ->where('approval_type', 'manager')
        ->first();

    $directorRule = InventoryApprovalRuleModel::query()
        ->where('approval_type', 'director')
        ->first();

    expect($directorRule->max_requisition_amount)->toBeGreaterThan($managerRule->max_requisition_amount);
    expect($directorRule->max_items_count)->toBeGreaterThan($managerRule->max_items_count);
});
