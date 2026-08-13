<?php

namespace App\Support\ApprovalWorkflow;

use App\Models\User;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalDecisionModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalRuleModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowInstanceModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryApprovalWorkflowModel;
use App\Modules\InventoryProcurement\Infrastructure\Models\InventoryDepartmentRequisitionModel;
use App\Support\Audit\SodAlertNotifier;
use Illuminate\Support\Facades\DB;

class ApprovalWorkflowEngine
{
    /**
     * Initiate approval workflow for a requisition
     *
     * If timeoutHours is null, uses the default from config.
     * Set timeoutHours to 0 for no timeout.
     */
    public function initiateWorkflow(
        string $tenantId,
        InventoryDepartmentRequisitionModel $requisition,
        InventoryApprovalWorkflowModel $workflow,
        ?int $timeoutHours = null
    ): InventoryApprovalWorkflowInstanceModel {
        $steps = $workflow->getApprovalSteps();
        $totalSteps = count($steps);

        $timeoutAt = $this->resolveTimeout($timeoutHours);

        $instance = InventoryApprovalWorkflowInstanceModel::create([
            'tenant_id' => $tenantId,
            'workflow_id' => $workflow->id,
            'requisition_id' => $requisition->id,
            'current_step' => 'step_1',
            'step_number' => 1,
            'total_steps' => $totalSteps,
            'workflow_version' => $workflow->version ?? 1,
            'status' => 'in_progress',
            'started_at' => now(),
            'timeout_at' => $timeoutAt,
        ]);

        return $instance;
    }

    /**
     * Resolve the timeout timestamp for a new workflow instance
     */
    private function resolveTimeout(?int $timeoutHours): ?\Illuminate\Support\Carbon
    {
        if ($timeoutHours === 0) {
            return null;
        }

        $hours = $timeoutHours ?? (int) config('inventory_retention.approval_timeout.default_hours', 72);

        if ($hours <= 0) {
            return null;
        }

        return now()->addHours($hours);
    }

    /**
     * Record an approval decision for the current step
     */
    public function recordDecision(
        User $approver,
        InventoryApprovalWorkflowInstanceModel $instance,
        string $decision,
        ?string $comments = null,
        bool $sodViolationFlagged = false,
        ?string $sodViolationReason = null
    ): InventoryApprovalDecisionModel {
        $requisition = $instance->requisition;
        $stepConfig = $instance->getCurrentStepConfig();

        // Record the decision
        $decisionRecord = InventoryApprovalDecisionModel::create([
            'tenant_id' => $instance->tenant_id,
            'workflow_instance_id' => $instance->id,
            'approver_user_id' => $approver->id,
            'step_number' => $instance->step_number,
            'step_type' => $stepConfig['type'] ?? 'unknown',
            'decision' => $decision,
            'comments' => $comments,
            'approver_department_id' => $approver->staffProfile?->department_id,
            'approver_job_title' => $approver->staffProfile?->job_title,
            'requisition_requester_id' => $requisition->requested_by_user_id,
            'sod_violation_flagged' => $sodViolationFlagged,
            'sod_violation_reason' => $sodViolationReason,
            'decided_at' => now(),
        ]);

        // Send alert if SOD violation was flagged
        if ($sodViolationFlagged && $sodViolationReason) {
            app(SodAlertNotifier::class)->notifyViolation($approver, $instance, $sodViolationReason);
        }

        // Handle rejected decision (stop workflow)
        if ($decision === 'rejected') {
            $instance->update([
                'status' => 'rejected',
                'current_step' => 'rejected',
                'rejected_at' => now(),
            ]);
            
            $requisition->update(['status' => 'rejected']);
            return $decisionRecord;
        }

        // Check if current step is complete
        if ($instance->isCurrentStepComplete()) {
            // Move to next step or mark as approved
            if ($instance->hasNextStep()) {
                $nextStep = $instance->getNextStepNumber();
                $instance->update([
                    'step_number' => $nextStep,
                    'current_step' => 'step_' . $nextStep,
                ]);
            } else {
                // All steps complete - workflow approved
                $instance->update([
                    'status' => 'approved',
                    'current_step' => 'approved',
                    'completed_at' => now(),
                ]);
                
                $requisition->update(['status' => 'approved']);
            }
        }

        return $decisionRecord;
    }

    /**
     * Check if approver can approve requisition
     *
     * @return array{can_approve: bool, reason?: string}
     */
    public function canApproveRequisition(
        User $approver,
        InventoryApprovalWorkflowInstanceModel $instance
    ): array {
        $requisition = $instance->requisition;

        // Check if workflow is still in progress
        if (!$instance->isInProgress()) {
            return [
                'can_approve' => false,
                'reason' => 'Workflow is not in progress',
            ];
        }

        // Get approval rules for this approver and current step
        $stepConfig = $instance->getCurrentStepConfig();
        if (!$stepConfig) {
            return [
                'can_approve' => false,
                'reason' => 'Step configuration not found',
            ];
        }

        // Get approval rule for this approver
        $rule = InventoryApprovalRuleModel::where('tenant_id', $instance->tenant_id)
            ->where('facility_id', $requisition->facility_id)
            ->where('approval_type', $stepConfig['type'] ?? 'unknown')
            ->where('status', 'active')
            ->first();

        if (!$rule) {
            return [
                'can_approve' => false,
                'reason' => 'No approval rule found for this role',
            ];
        }

        // Check if rule allows this approver (role/department check)
        if (!$this->userMatchesApprovalRule($approver, $rule, $requisition)) {
            return [
                'can_approve' => false,
                'reason' => 'User does not match approval rule requirements',
            ];
        }

        // Check if approver has already approved this step
        if ($instance->decisions()
            ->where('step_number', $instance->step_number)
            ->where('approver_user_id', $approver->id)
            ->exists()
        ) {
            return [
                'can_approve' => false,
                'reason' => 'You have already approved this step',
            ];
        }

        // Check authority limits (item count and category restrictions)
        if (!$rule->canApproveRequisition($requisition)) {
            return [
                'can_approve' => false,
                'reason' => 'Exceeds approval authority limits',
            ];
        }

        return ['can_approve' => true];
    }

    /**
     * Check if requisition meets segregation of duties requirements
     *
     * @return array{sod_compliant: bool, violation_reason?: string}
     */
    public function checkSegregationOfDuties(
        User $approver,
        InventoryApprovalWorkflowInstanceModel $instance
    ): array {
        $requisition = $instance->requisition;
        $requester = User::find($requisition->requested_by_user_id);

        // SOD violation: Same person is requester and approver
        if ($approver->id === $requester->id) {
            return [
                'sod_compliant' => false,
                'violation_reason' => 'Requester cannot approve own requisition',
            ];
        }

        // Additional SOD check: In same department
        $approverDept = $approver->staffProfile?->department_id;
        $requesterDept = $requester->staffProfile?->department_id;

        if ($approverDept && $requesterDept && $approverDept === $requesterDept) {
            // This might be allowed depending on hierarchy, but flag it
            return [
                'sod_compliant' => true,
                'warning' => 'Approver and requester are in same department',
            ];
        }

        return ['sod_compliant' => true];
    }

    /**
     * Get pending approvals for a user
     */
    public function getPendingApprovalsForUser(
        string $tenantId,
        User $approver,
        ?string $approvalType = null
    ): \Illuminate\Database\Eloquent\Collection {
        $query = InventoryApprovalWorkflowInstanceModel::query()
            ->with(['requisition', 'workflow', 'decisions'])
            ->where('tenant_id', $tenantId)
            ->where('status', 'in_progress');

        if ($approvalType) {
            $query->whereHas('workflow', fn($q) => 
                $q->where('trigger_type', $approvalType)
            );
        }

        // Filter to approvals this user can action
        return $query->get()->filter(function ($instance) use ($approver) {
            return $this->canApproveRequisition($approver, $instance)['can_approve'] ?? false;
        });
    }

    /**
     * Recall workflow and send back to previous step
     *
     * Stores recall reference on the instance without mutating the
     * immutable decision record, preserving audit trail integrity.
     */
    public function recallWorkflow(
        InventoryApprovalWorkflowInstanceModel $instance,
        ?string $reason = null
    ): void {
        // Get the most recent decision to reference (without modifying it)
        $lastDecision = $instance->decisions()
            ->latest('created_at')
            ->first();

        // Mark as recalled, preserving reference to last decision
        $instance->update([
            'status' => 'recalled',
            'current_step' => 'recalled',
            'recalled_decision_id' => $lastDecision?->id,
            'recall_reason' => $reason,
        ]);

        // Update requisition status
        $instance->requisition->update(['status' => 'pending']);
    }

    /**
     * Check if user matches approval rule requirements
     */
    private function userMatchesApprovalRule(
        User $approver,
        InventoryApprovalRuleModel $rule,
        InventoryDepartmentRequisitionModel $requisition
    ): bool {
        // If rule has a specific role, check if user has it
        if ($rule->role_id) {
            if (!$approver->roles->contains('id', $rule->role_id)) {
                return false;
            }
        }

        // If rule has a specific department, check if user is in it
        if ($rule->department_id) {
            $approverDept = $approver->staffProfile?->department_id;
            if ($approverDept !== (string) $rule->department_id) {
                return false;
            }
        }

        // Check if rule allows approving for the requisition's department
        if (!$rule->canApproveOtherDepartments()) {
            $approverDept = $approver->staffProfile?->department_id;
            if ($approverDept !== (string) $requisition->requesting_department_id) {
                return false;
            }
        }

        return true;
    }
}
