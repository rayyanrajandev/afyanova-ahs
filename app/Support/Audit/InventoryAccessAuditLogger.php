<?php

namespace App\Support\Audit;

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryAccessAuditLogModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Inventory Access Audit Logger
 * Phase 1: Department-Level RBAC Implementation
 *
 * Responsible for logging all inventory access actions with business context
 * and compliance information for regulatory auditing
 */
class InventoryAccessAuditLogger
{
    public function __construct(
        private readonly PiiSanitizer $piiSanitizer = new PiiSanitizer()
    ) {}

    /**
     * Log inventory access action with business context
     *
     * @param User $actor
     * @param string $action
     * @param string $resourceType
     * @param Model $resource
     * @param array|null $changes
     * @param array|null $businessContext
     * @param array|null $complianceFlags
     * @return InventoryAccessAuditLogModel
     */
    public function logAction(
        User $actor,
        string $action,
        string $resourceType,
        ?Model $resource = null,
        ?array $changes = null,
        ?array $businessContext = null,
        ?array $complianceFlags = null
    ): InventoryAccessAuditLogModel {
        return InventoryAccessAuditLogModel::create([
            'tenant_id' => $actor->tenant_id,
            'facility_id' => $this->getFacilityId($actor),
            'department_id' => $this->getDepartmentId($actor),
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_department' => $actor->staffProfile?->department,
            'action_timestamp' => now(),
            'resource_type' => $resourceType,
            'resource_id' => $resource?->id,
            'resource_name' => $resource ? $this->getResourceName($resource) : null,
            'before_state' => $changes['before'] ?? null,
            'after_state' => $changes['after'] ?? null,
            'changes' => $changes['changed'] ?? null,
            'business_context' => $this->piiSanitizer->sanitizeArray($businessContext ?? [], ['comments']),
            'compliance_flags' => $complianceFlags,
            'created_at' => now(),
        ]);
    }

    /**
     * Log access denial
     *
     * @param User $actor
     * @param string $action
     * @param string $denialReason
     * @param array $permissionsChecked
     * @param string $resourceType
     * @param string $resourceId
     * @param array $complianceFlags
     * @return InventoryAccessAuditLogModel
     */
    public function logAccessDenial(
        User $actor,
        string $action,
        string $denialReason,
        array $permissionsChecked,
        string $resourceType,
        string $resourceId,
        array $complianceFlags = []
    ): InventoryAccessAuditLogModel {
        return InventoryAccessAuditLogModel::create([
            'tenant_id' => $actor->tenant_id,
            'facility_id' => $this->getFacilityId($actor),
            'department_id' => $this->getDepartmentId($actor),
            'action' => $action,
            'actor_id' => $actor->id,
            'actor_department' => $actor->staffProfile?->department,
            'action_timestamp' => now(),
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'access_decision' => 'deny',
            'deny_reason' => $this->piiSanitizer->sanitizeFreeText($denialReason),
            'permissions_checked' => $permissionsChecked,
            'compliance_flags' => $complianceFlags,
            'created_at' => now(),
        ]);
    }

    /**
     * Log requisition action with full context
     *
     * @param User $actor
     * @param InventoryDepartmentRequisitionModel $requisition
     * @param string $action
     * @param string|null $comments
     * @return InventoryAccessAuditLogModel
     */
    public function logRequisitionAction(
        User $actor,
        InventoryDepartmentRequisitionModel $requisition,
        string $action,
        ?string $comments = null
    ): InventoryAccessAuditLogModel {
        // Reload the requisition with lines relationship
        $requisition->load('lines');
        $items = $requisition->lines;
        
        // Calculate total cost from lines if possible
        $totalCost = 0;
        try {
            $totalCost = $requisition->lines()
                ->join('inventory_items', 'inventory_department_requisition_lines.item_id', '=', 'inventory_items.id')
                ->sum(DB::raw('inventory_department_requisition_lines.requested_quantity'));
        } catch (\Exception) {
            // If calculation fails, just use 0
        }

        $businessContext = [
            'requisition_number' => $requisition->requisition_number,
            'requesting_department' => $requisition->requesting_department,
            'items_count' => $items->count(),
            'total_cost' => $totalCost,
            'currency' => 'USD',
            'contains_controlled_substances' => false, // Will be determined from items if available
            'urgency' => $requisition->priority ?? 'normal',
            'needed_by' => $requisition->needed_by?->format('Y-m-d'),
            'comments' => $this->piiSanitizer->sanitizeFreeText($comments),
        ];

        $complianceFlags = [
            'controlled_substance' => false,
            'high_value' => $totalCost >= 5000,
            'urgent' => $requisition->priority === 'urgent',
        ];

        return $this->logAction(
            $actor,
            $action,
            'requisition',
            $requisition,
            null,
            $businessContext,
            $complianceFlags
        );
    }

    /**
     * Log segregation of duties violation
     *
     * @param User $actor
     * @param InventoryDepartmentRequisitionModel $requisition
     * @param string $reason
     * @return InventoryAccessAuditLogModel
     */
    public function logSegregationOfDutiesViolation(
        User $actor,
        InventoryDepartmentRequisitionModel $requisition,
        string $reason
    ): InventoryAccessAuditLogModel {
        return $this->logAccessDenial(
            $actor,
            'inventory_requisition.segregation_of_duties_violation',
            $this->piiSanitizer->sanitizeFreeText($reason),
            ['inventory.approve-requisition-own-department'],
            'requisition',
            $requisition->id,
            [
                'segregation_check' => 'failed',
                'violation_type' => 'requester_and_approver_same',
            ]
        );
    }

    /**
     * Get facility ID from user
     *
     * @param User $user
     * @return string|null
     */
    private function getFacilityId(User $user): ?string
    {
        // Get facility_id from facility_user table
        return DB::table('facility_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->value('facility_id');
    }

    /**
     * Get department ID from user
     *
     * @param User $user
     * @return string|null
     */
    private function getDepartmentId(User $user): ?string
    {
        return $user->staffProfile?->department_id;
    }

    /**
     * Get resource display name
     *
     * @param Model $resource
     * @return string
     */
    private function getResourceName(Model $resource): string
    {
        return match ($resource::class) {
            'App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel' => $resource->requisition_number ?? $resource->id,
            'App\Modules\InventoryProcurement\Infrastructure\Models\InventoryWarehouseTransferModel' => $resource->transfer_number ?? $resource->id,
            'App\Modules\Platform\Infrastructure\Models\RoleModel' => $resource->code ?? $resource->name,
            default => $resource->name ?? $resource->id,
        };
    }
}
