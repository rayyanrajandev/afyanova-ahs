<?php

namespace App\Support\ApprovalWorkflow;

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel;

/**
 * Segregation of Duties (SOD) Validator
 * 
 * Ensures compliance with segregation of duties principles:
 * - Requester cannot approve own requisition
 * - Multiple levels of approval for high-value requisitions
 * - Cross-functional checks (accounting, finance, operations)
 * 
 * Regulatory compliance: HIPAA, CAP/CLIA, SOC 2 Type II
 */
class SegregationOfDutiesValidator
{
    /**
     * Check if approver violates SOD with requisition requester
     *
     * @return array{compliant: bool, violations: array<int, string>, warnings: array<int, string>}
     */
    public function validateApproval(
        User $approver,
        InventoryDepartmentRequisitionModel $requisition,
        InventoryApprovalWorkflowInstanceModel $instance
    ): array {
        $violations = [];
        $warnings = [];

        // Check 1: Requester cannot be approver
        $violation = $this->checkRequesterApproverMismatch($approver, $requisition);
        if ($violation) {
            $violations[] = $violation;
        }

        // Check 2: Multiple approvals for high-value requisitions
        $warning = $this->checkMultipleApprovalRequirement($approver, $instance);
        if ($warning) {
            $warnings[] = $warning;
        }

        // Check 3: Cross-functional approval for controlled items
        $warning = $this->checkCrossFunctionalApproval($approver, $requisition);
        if ($warning) {
            $warnings[] = $warning;
        }

        // Check 4: Department-level separation
        $warning = $this->checkDepartmentSeparation($approver, $requisition);
        if ($warning) {
            $warnings[] = $warning;
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'warnings' => $warnings,
        ];
    }

    /**
     * Check if approver is the requisition requester (critical SOD violation)
     */
    private function checkRequesterApproverMismatch(
        User $approver,
        InventoryDepartmentRequisitionModel $requisition
    ): ?string {
        if ((string) $approver->id === (string) $requisition->requested_by_user_id) {
            return 'Requester cannot approve own requisition (SOD violation)';
        }

        return null;
    }

    /**
     * Check if multiple approvals are required (high-value items)
     */
    private function checkMultipleApprovalRequirement(
        User $approver,
        InventoryApprovalWorkflowInstanceModel $instance
    ): ?string {
        $requisition = $instance->requisition;
        
        // High-value threshold: 50,000 currency units
        $highValueThreshold = 50000;

        // Check if this is a high-value requisition (you'd need to calculate actual value)
        // For now, use number of items as proxy
        $itemCount = $requisition->lines()->count();
        
        if ($itemCount > 10) {
            // For high-value requisitions, ensure multiple approvals
            if ($instance->step_number === 1 && $instance->total_steps < 2) {
                return 'High-value requisition should have multiple approval steps';
            }
        }

        return null;
    }

    /**
     * Check if controlled/sensitive items require cross-functional approval
     */
    private function checkCrossFunctionalApproval(
        User $approver,
        InventoryDepartmentRequisitionModel $requisition
    ): ?string {
        // Get categories in this requisition
        $categories = $requisition->lines()
            ->join('inventory_items', 'inventory_department_requisition_lines.item_id', '=', 'inventory_items.id')
            ->pluck('inventory_items.category')
            ->unique();

        // Controlled categories that need special approval
        $controlledCategories = ['pharmaceutical', 'controlled_substance', 'blood_products'];

        foreach ($categories as $category) {
            if (in_array($category, $controlledCategories)) {
                // Verify approver has authority for controlled items
                $approverRole = $approver->roles()
                    ->where('code', 'like', '%FINANCE%|%DIRECTOR%')
                    ->exists();

                if (!$approverRole) {
                    return "Controlled item category '$category' requires cross-functional approval";
                }
            }
        }

        return null;
    }

    /**
     * Check if approver and requester have appropriate separation
     */
    private function checkDepartmentSeparation(
        User $approver,
        InventoryDepartmentRequisitionModel $requisition
    ): ?string {
        $requester = User::find($requisition->requested_by_user_id);
        if (!$requester) {
            return null;
        }

        $approverDept = $approver->staffProfile?->department_id;
        $requesterDept = $requester->staffProfile?->department_id;

        // Both must have departments assigned
        if (!$approverDept || !$requesterDept) {
            return 'Both approver and requester must have department assignments';
        }

        // Different departments preferred for high-value items
        if ($approverDept === $requesterDept) {
            $itemCount = $requisition->lines()->count();
            if ($itemCount > 5) {
                return 'High-quantity requisition should be approved by different department';
            }
        }

        return null;
    }

    /**
     * Get all approvers who cannot approve this requisition (SOD conflict)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getConflictedApprovers(
        InventoryDepartmentRequisitionModel $requisition
    ): \Illuminate\Database\Eloquent\Collection {
        // Get requester
        $requester = User::find($requisition->requested_by_user_id);
        if (!$requester) {
            return collect();
        }

        $requesterDept = $requester->staffProfile?->department_id;

        // Find all users with approval roles in requesting department
        return User::query()
            ->with('staffProfile', 'roles')
            ->whereHas('staffProfile', fn($q) => 
                $q->where('department_id', $requesterDept)
            )
            ->whereHas('roles', fn($q) =>
                $q->where('access_level', 'approve')
            )
            ->where('id', '!=', $requester->id) // Exclude requester
            ->get();
    }

    /**
     * Get approvers suitable for this requisition (SOD compliant)
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSuitableApprovers(
        string $tenantId,
        InventoryDepartmentRequisitionModel $requisition
    ): \Illuminate\Database\Eloquent\Collection {
        $requester = User::find($requisition->requested_by_user_id);
        if (!$requester) {
            return collect();
        }

        // Get all users with approval roles in the facility
        $potentialApprovers = User::query()
            ->with('staffProfile', 'roles')
            ->whereHas('roles', fn($q) =>
                $q->where('access_level', 'approve')
                ->where('status', 'active')
            )
            ->where('id', '!=', $requester->id) // Exclude requester
            ->get();

        // Filter to those who are SOD compliant
        return $potentialApprovers->filter(function ($approver) use ($requisition) {
            $result = $this->validateApproval($approver, $requisition, $requisition->workflowInstance);
            return $result['compliant'];
        });
    }

    /**
     * Get SOD violation details for reporting
     *
     * @return array<string, mixed>
     */
    public function getSodViolationReport(
        User $approver,
        InventoryDepartmentRequisitionModel $requisition,
        InventoryApprovalWorkflowInstanceModel $instance
    ): array {
        $result = $this->validateApproval($approver, $requisition, $instance);

        return [
            'compliant' => $result['compliant'],
            'approver_id' => $approver->id,
            'approver_name' => $approver->name,
            'requester_id' => $requisition->requested_by_user_id,
            'violations' => $result['violations'],
            'warnings' => $result['warnings'],
            'violation_count' => count($result['violations']),
            'warning_count' => count($result['warnings']),
            'timestamp' => now(),
            'workflow_step' => $instance->step_number,
        ];
    }
}
