<?php

namespace App\Modules\InventoryProcurement\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Department\Infrastructure\Models\DepartmentModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryItemModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseModel;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\ApproveRequisitionRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\RecallRequisitionRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Requests\StoreDepartmentRequisitionWithAccessControlRequest;
use App\Modules\InventoryProcurement\Presentation\Http\Transformers\InventoryDepartmentRequisitionResponseTransformer;
use App\Modules\Platform\Domain\Services\CurrentPlatformScopeContextInterface;
use App\Support\Audit\InventoryAccessAuditLogger;
use App\Support\Auth\DepartmentScopedPermissionResolver;
use App\Support\ApprovalWorkflow\ApprovalWorkflowEngine;
use App\Support\ApprovalWorkflow\SegregationOfDutiesValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Phase 1 & 2: Department-Level RBAC with Multi-Role Approvals
 * Controller for department-scoped inventory requisitions with access control and approval workflows
 *
 * Phase 1 enforces:
 * - Department-scoped item access (users can only see items in their department's warehouse)
 * - Department-scoped requisition creation (users can only request from their assigned department)
 * - Temporal access control (roles expire at effective_until date)
 * - Audit logging for all access decisions
 *
 * Phase 2 enforces:
 * - Multi-step approval workflows with configurable steps
 * - Segregation of duties (requester ≠ approver)
 * - Role-based approval authority with amount/category limits
 * - Compliance audit logging for all approval decisions
 */
class InventoryDepartmentAccessController extends Controller
{
    public function __construct(
        private readonly CurrentPlatformScopeContextInterface $platformScopeContext,
        private readonly DepartmentScopedPermissionResolver $permissionResolver,
        private readonly InventoryAccessAuditLogger $auditLogger,
        private readonly ApprovalWorkflowEngine $workflowEngine,
        private readonly SegregationOfDutiesValidator $sodValidator,
    ) {}

    /**
     * Resolve user's assigned department model from staff profile
     *
     * @param \App\Models\User $user
     * @return DepartmentModel|null
     */
    private function resolveUserDepartment(\App\Models\User $user): ?DepartmentModel
    {
        $staffProfile = $user->staffProfile;
        if (!$staffProfile || !$staffProfile->department_id) {
            return null;
        }

        return DepartmentModel::find($staffProfile->department_id);
    }

    /**
     * List inventory items available to user's department
     * GET /api/v1/inventory-department/items
     *
     * Returns paginated list of items in user's department warehouse
     * Only returns items the user has permission to view based on their department
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listItems(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();

        // Get user's department
        $userDepartment = $this->resolveUserDepartment($user);
        if (!$userDepartment) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.list-items',
                'User not assigned to any department',
                ['inventory.view-own-items'],
                'inventory_item',
                'bulk',
                ['missing_department_assignment' => true]
            );

            return response()->json(
                ['error' => 'User must be assigned to a department to view inventory'],
                403
            );
        }

        // Check permission for own department
        if (!$this->permissionResolver->hasPermissionInDepartment(
            $user,
            'inventory.view-own-items',
            $userDepartment
        )) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.list-items',
                'User lacks inventory.view-own-items permission',
                ['inventory.view-own-items'],
                'inventory_item',
                'bulk',
                ['permission_check' => 'failed']
            );

            return response()->json(
                ['error' => 'You do not have permission to view inventory items'],
                403
            );
        }

        // Get user's department warehouse
        $warehouse = InventoryWarehouseModel::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $userDepartment->id)
            ->first();

        if (!$warehouse) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 0,
                    'per_page' => 50,
                ]
            ]);
        }

        // Query items in warehouse
        $page = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 50), 100);
        $search = $request->query('search');
        $category = $request->query('category');

        $query = InventoryItemModel::query()
            ->where('tenant_id', $tenantId)
            ->where('default_warehouse_id', $warehouse->id)
            ->where('status', 'active');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('item_code', 'ilike', "%{$search}%")
                    ->orWhere('item_name', 'ilike', "%{$search}%");
            });
        }

        if ($category) {
            $query->where('category', $category);
        }

        $items = $query->paginate($perPage, ['*'], 'page', $page);

        // Log successful access
        $this->auditLogger->logAction(
            $user,
            'inventory.list-items',
            'inventory_item',
            $warehouse,
            null,
            [
                'item_count' => $items->total(),
                'page' => $page,
                'per_page' => $perPage,
            ],
            ['access_granted' => true]
        );

        return response()->json([
            'data' => array_map(fn($item) => $item->toArray(), $items->items()),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'total' => $items->total(),
                'per_page' => $items->perPage(),
            ]
        ]);
    }

    /**
     * Get single inventory item
     * GET /api/v1/inventory-department/items/{itemId}
     *
     * @param Request $request
     * @param string $itemId
     * @return JsonResponse
     */
    public function getItem(Request $request, string $itemId): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();

        $userDepartment = $this->resolveUserDepartment($user);
        if (!$userDepartment) {
            return response()->json(['error' => 'User must be assigned to a department'], 403);
        }

        if (!$this->permissionResolver->hasPermissionInDepartment(
            $user,
            'inventory.view-own-items',
            $userDepartment
        )) {
            return response()->json(['error' => 'You do not have permission to view inventory items'], 403);
        }

        $warehouse = InventoryWarehouseModel::query()
            ->where('tenant_id', $tenantId)
            ->where('department_id', $userDepartment->id)
            ->first();

        if (!$warehouse) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $item = InventoryItemModel::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $itemId)
            ->where('default_warehouse_id', $warehouse->id)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        return response()->json([
            'data' => $item->toArray()
        ]);
    }

    /**
     * Create department requisition with access control
     * POST /api/v1/inventory-department/requisitions
     *
     * Creates a new department requisition with full audit logging
     * Only allows creation by users with appropriate department and permission level
     *
     * @param StoreDepartmentRequisitionWithAccessControlRequest $request
     * @return JsonResponse
     */
    public function createRequisition(StoreDepartmentRequisitionWithAccessControlRequest $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();
        $data = $request->validated();

        // Get requesting department
        $requestingDepartment = DepartmentModel::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $data['requestingDepartmentId'])
            ->first();

        if (!$requestingDepartment) {
            return response()->json(['error' => 'Requesting department not found'], 404);
        }

        // Verify user is in requesting department
        $userDepartment = $this->resolveUserDepartment($user);
        if (!$userDepartment || (string) $userDepartment->id !== (string) $requestingDepartment->id) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.create-requisition-own-department',
                'User is not in the requesting department',
                ['inventory.create-requisition-own-department'],
                'requisition',
                $data['requestingDepartmentId'],
                ['department_mismatch' => true]
            );

            return response()->json(
                ['error' => 'You can only create requisitions for your own department'],
                403
            );
        }

        // Check permission
        if (!$this->permissionResolver->hasPermissionInDepartment(
            $user,
            'inventory.create-requisition-own-department',
            $requestingDepartment
        )) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.create-requisition-own-department',
                'User lacks permission to create requisitions',
                ['inventory.create-requisition-own-department'],
                'requisition',
                $data['requestingDepartmentId'],
                ['permission_check' => 'failed']
            );

            return response()->json(['error' => 'You do not have permission to create requisitions'], 403);
        }

        // Create requisition
        try {
            // Generate unique requisition number
            $requisitionNumber = 'REQ-' . now()->format('YmdHis') . '-' . Str::random(6);
            
            $requisition = InventoryDepartmentRequisitionModel::create([
                'requisition_number' => $requisitionNumber,
                'tenant_id' => $tenantId,
                'facility_id' => $this->platformScopeContext->facilityId(),
                'requesting_department' => $requestingDepartment->name,
                'requesting_department_id' => $requestingDepartment->id,
                'issuing_warehouse_id' => $data['issuingWarehouseId'],
                'requested_by_user_id' => $user->id,
                'priority' => $data['priority'] ?? 'normal',
                'needed_by' => $data['neededBy'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'pending',
            ]);

            // Add lines
            foreach ($data['lines'] as $line) {
                $requisition->lines()->create([
                    'item_id' => $line['itemId'],
                    'requested_quantity' => $line['requestedQuantity'],
                    'unit' => $line['unit'],
                    'notes' => $line['notes'] ?? null,
                ]);
            }

            // Log action
            $this->auditLogger->logRequisitionAction(
                $user,
                $requisition,
                'inventory_requisition.created',
                $data['notes'] ?? null
            );

            return response()->json([
                'data' => InventoryDepartmentRequisitionResponseTransformer::transform($requisition->toArray())
            ], 201);
        } catch (\Exception $e) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.create-requisition-own-department',
                'Error creating requisition: ' . $e->getMessage(),
                ['inventory.create-requisition-own-department'],
                'requisition',
                $data['requestingDepartmentId'],
                ['error' => true]
            );

            return response()->json(['error' => 'Failed to create requisition'], 500);
        }
    }

    /**
     * List department requisitions for user
     * GET /api/v1/inventory-department/requisitions
     *
     * Returns requisitions for user's department
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listRequisitions(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();

        $userDepartment = $this->resolveUserDepartment($user);
        if (!$userDepartment) {
            return response()->json(['error' => 'User must be assigned to a department'], 403);
        }

        if (!$this->permissionResolver->hasPermissionInDepartment(
            $user,
            'inventory.view-own-items',
            $userDepartment
        )) {
            return response()->json(['error' => 'You do not have permission to view requisitions'], 403);
        }

        $page = (int) $request->query('page', 1);
        $perPage = min((int) $request->query('per_page', 50), 100);
        $status = $request->query('status');

        $query = InventoryDepartmentRequisitionModel::query()
            ->where('tenant_id', $tenantId)
            ->where('requesting_department_id', $userDepartment->id)
            ->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        $requisitions = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => array_map(
                [InventoryDepartmentRequisitionResponseTransformer::class, 'transform'],
                array_map(fn($req) => $req->toArray(), $requisitions->items())
            ),
            'meta' => [
                'current_page' => $requisitions->currentPage(),
                'last_page' => $requisitions->lastPage(),
                'total' => $requisitions->total(),
                'per_page' => $requisitions->perPage(),
            ]
        ]);
    }

    /**
     * Get single requisition
     * GET /api/v1/inventory-department/requisitions/{requisitionId}
     *
     * @param Request $request
     * @param string $requisitionId
     * @return JsonResponse
     */
    public function getRequisition(Request $request, string $requisitionId): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();

        $userDepartment = $this->resolveUserDepartment($user);
        if (!$userDepartment) {
            return response()->json(['error' => 'User must be assigned to a department'], 403);
        }

        if (!$this->permissionResolver->hasPermissionInDepartment(
            $user,
            'inventory.view-own-items',
            $userDepartment
        )) {
            return response()->json(['error' => 'You do not have permission to view requisitions'], 403);
        }

        $requisition = InventoryDepartmentRequisitionModel::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $requisitionId)
            ->where('requesting_department_id', $userDepartment->id)
            ->first();

        if (!$requisition) {
            return response()->json(['error' => 'Requisition not found'], 404);
        }

        return response()->json([
            'data' => InventoryDepartmentRequisitionResponseTransformer::transform($requisition->toArray())
        ]);
    }

    /**
     * List pending approvals for current user
     * GET /api/v1/inventory-department/approvals/pending
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function listPendingApprovals(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();

        // Get pending approvals for this user
        $pendingApprovals = $this->workflowEngine->getPendingApprovalsForUser(
            $tenantId,
            $user
        );

        // Transform to response
        $data = $pendingApprovals->map(function ($instance) {
            return [
                'id' => $instance->id,
                'requisition_id' => $instance->requisition_id,
                'requisition_number' => $instance->requisition->requisition_number,
                'requesting_department' => $instance->requisition->requesting_department,
                'workflow_status' => $instance->status,
                'current_step' => $instance->step_number,
                'total_steps' => $instance->total_steps,
                'created_at' => $instance->started_at?->toIso8601String(),
            ];
        });

        // Log access
        $this->auditLogger->logAction(
            $user,
            'inventory.list-pending-approvals',
            'requisition',
            null,
            null,
            ['approvals_count' => $pendingApprovals->count()],
            ['access_granted' => true]
        );

        return response()->json([
            'data' => $data->values(),
            'meta' => ['total' => $data->count()],
        ]);
    }

    /**
     * Approve a requisition
     * POST /api/v1/inventory-department/approvals/{workflowInstanceId}/approve
     *
     * @param ApproveRequisitionRequest $request
     * @param string $workflowInstanceId
     * @return JsonResponse
     */
    public function approveRequisition(
        ApproveRequisitionRequest $request,
        string $workflowInstanceId
    ): JsonResponse {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();
        $data = $request->validated();

        // Get workflow instance
        $instance = InventoryApprovalWorkflowInstanceModel::query()
            ->with(['workflow', 'requisition', 'decisions'])
            ->where('tenant_id', $tenantId)
            ->where('id', $workflowInstanceId)
            ->first();

        if (!$instance) {
            return response()->json(['error' => 'Workflow instance not found'], 404);
        }

        $requisition = $instance->requisition;

        // Check if user can approve
        $canApprove = $this->workflowEngine->canApproveRequisition($user, $instance);
        if (!$canApprove['can_approve']) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.approve-requisition',
                $canApprove['reason'] ?? 'User cannot approve',
                ['inventory.approve-requisition'],
                'requisition',
                $requisition->id,
                ['approval_check' => 'failed']
            );

            return response()->json(['error' => $canApprove['reason'] ?? 'Cannot approve'], 403);
        }

        // Check segregation of duties
        $sodResult = $this->sodValidator->validateApproval($user, $requisition, $instance);
        if (!$sodResult['compliant']) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.approve-requisition',
                'SOD violation: ' . implode(', ', $sodResult['violations']),
                ['inventory.approve-requisition'],
                'requisition',
                $requisition->id,
                [
                    'sod_violation' => true,
                    'violations' => $sodResult['violations'],
                ]
            );

            return response()->json([
                'error' => 'Cannot approve: ' . $sodResult['violations'][0],
                'violations' => $sodResult['violations'],
            ], 403);
        }

        // Record decision
        try {
            $decision = $this->workflowEngine->recordDecision(
                $user,
                $instance,
                $data['decision'],
                $data['comments'] ?? null,
                !$sodResult['compliant'],
                !$sodResult['compliant'] ? implode('; ', $sodResult['violations']) : null
            );

            // Log approval action
            $this->auditLogger->logRequisitionAction(
                $user,
                $requisition,
                'inventory_requisition.approved',
                $data['comments'] ?? null
            );

            // Reload instance to get updated status
            $instance->refresh();

            return response()->json([
                'data' => [
                    'decision_id' => $decision->id,
                    'workflow_status' => $instance->status,
                    'current_step' => $instance->step_number,
                    'total_steps' => $instance->total_steps,
                    'requisition_status' => $requisition->status,
                    'message' => $instance->isCompleted() 
                        ? 'Requisition ' . $instance->status 
                        : 'Decision recorded, workflow progressing to step ' . $instance->step_number,
                ]
            ], 200);
        } catch (\Exception $e) {
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.approve-requisition',
                'Error recording decision: ' . $e->getMessage(),
                ['inventory.approve-requisition'],
                'requisition',
                $requisition->id,
                ['error' => true]
            );

            return response()->json(['error' => 'Failed to record approval decision'], 500);
        }
    }

    /**
     * Reject a requisition
     * POST /api/v1/inventory-department/approvals/{workflowInstanceId}/reject
     *
     * @param ApproveRequisitionRequest $request
     * @param string $workflowInstanceId
     * @return JsonResponse
     */
    public function rejectRequisition(
        ApproveRequisitionRequest $request,
        string $workflowInstanceId
    ): JsonResponse {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();
        $data = $request->validated();

        // Validate decision is rejection
        if ($data['decision'] !== 'rejected') {
            return response()->json(['error' => 'Use reject endpoint with "rejected" decision'], 400);
        }

        // Get workflow instance
        $instance = InventoryApprovalWorkflowInstanceModel::query()
            ->with(['workflow', 'requisition'])
            ->where('tenant_id', $tenantId)
            ->where('id', $workflowInstanceId)
            ->first();

        if (!$instance) {
            return response()->json(['error' => 'Workflow instance not found'], 404);
        }

        $requisition = $instance->requisition;

        // Check if user can approve
        $canApprove = $this->workflowEngine->canApproveRequisition($user, $instance);
        if (!$canApprove['can_approve']) {
            return response()->json(['error' => $canApprove['reason'] ?? 'Cannot reject'], 403);
        }

        // Record rejection
        try {
            $decision = $this->workflowEngine->recordDecision(
                $user,
                $instance,
                'rejected',
                $data['comments'] ?? null
            );

            // Log rejection
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.reject-requisition',
                'Requisition rejected: ' . ($data['comments'] ?? 'No reason provided'),
                ['inventory.approve-requisition'],
                'requisition',
                $requisition->id,
                ['rejection' => true]
            );

            return response()->json([
                'data' => [
                    'decision_id' => $decision->id,
                    'workflow_status' => $instance->status,
                    'requisition_status' => $requisition->status,
                    'message' => 'Requisition rejected',
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reject requisition'], 500);
        }
    }

    /**
     * Recall a requisition workflow
     * POST /api/v1/inventory-department/approvals/{workflowInstanceId}/recall
     *
     * @param RecallRequisitionRequest $request
     * @param string $workflowInstanceId
     * @return JsonResponse
     */
    public function recallRequisition(
        RecallRequisitionRequest $request,
        string $workflowInstanceId
    ): JsonResponse {
        $user = $request->user();
        $tenantId = $this->platformScopeContext->tenantId();
        $data = $request->validated();

        // Get workflow instance
        $instance = InventoryApprovalWorkflowInstanceModel::query()
            ->with(['workflow', 'requisition'])
            ->where('tenant_id', $tenantId)
            ->where('id', $workflowInstanceId)
            ->first();

        if (!$instance) {
            return response()->json(['error' => 'Workflow instance not found'], 404);
        }

        // Check if workflow is in progress
        if (!$instance->isInProgress()) {
            return response()->json(['error' => 'Cannot recall completed workflow'], 400);
        }

        // Check if user is the requester (can recall own requisition)
        if ((string) $user->id !== (string) $instance->requisition->requested_by_user_id) {
            return response()->json(['error' => 'Only the requester can recall the workflow'], 403);
        }

        try {
            $this->workflowEngine->recallWorkflow($instance, $data['reason']);

            // Log recall
            $this->auditLogger->logAccessDenial(
                $user,
                'inventory.recall-requisition',
                'Workflow recalled: ' . $data['reason'],
                ['inventory.create-requisition-own-department'],
                'requisition',
                $instance->requisition->id,
                ['recall' => true]
            );

            return response()->json([
                'data' => [
                    'workflow_status' => $instance->status,
                    'requisition_status' => $instance->requisition->status,
                    'message' => 'Requisition recalled to pending',
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to recall requisition'], 500);
        }
    }
}
