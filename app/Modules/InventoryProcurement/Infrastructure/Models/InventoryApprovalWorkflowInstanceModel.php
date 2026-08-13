<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApprovalWorkflowInstanceModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_approval_workflow_instances';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'workflow_id',
        'requisition_id',
        'current_step',
        'step_number',
        'total_steps',
        'workflow_version',
        'status',
        'started_at',
        'completed_at',
        'rejected_at',
        'timeout_at',
        'auto_rejected_at',
        'recalled_decision_id',
        'recall_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'timeout_at' => 'datetime',
            'auto_rejected_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the workflow definition
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(
            InventoryApprovalWorkflowModel::class,
            'workflow_id',
            'id'
        );
    }

    /**
     * Get the requisition being approved
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(
            InventoryDepartmentRequisitionModel::class,
            'requisition_id',
            'id'
        );
    }

    /**
     * Get all approval decisions for this workflow instance
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(
            InventoryApprovalDecisionModel::class,
            'workflow_instance_id',
            'id'
        );
    }

    /**
     * Get the recalled decision if workflow was recalled
     */
    public function recalledDecision(): BelongsTo
    {
        return $this->belongsTo(
            InventoryApprovalDecisionModel::class,
            'recalled_decision_id',
            'id'
        );
    }

    /**
     * Check if workflow was recalled
     */
    public function isRecalled(): bool
    {
        return $this->status === 'recalled';
    }

    /**
     * Get the current step configuration
     *
     * @return array<string, mixed>|null
     */
    public function getCurrentStepConfig(): ?array
    {
        return $this->workflow->getStep($this->step_number);
    }

    /**
     * Check if workflow is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Check if workflow is completed (approved or rejected)
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['approved', 'rejected']);
    }

    /**
     * Check if this is the last step
     */
    public function isLastStep(): bool
    {
        return $this->step_number >= $this->total_steps;
    }

    /**
     * Get next step number
     */
    public function getNextStepNumber(): int
    {
        return $this->step_number + 1;
    }

    /**
     * Check if there are more steps
     */
    public function hasNextStep(): bool
    {
        return !$this->isLastStep();
    }

    /**
     * Check if all required approvals are complete for current step
     */
    public function isCurrentStepComplete(): bool
    {
        $stepConfig = $this->getCurrentStepConfig();
        if (!$stepConfig) {
            return false;
        }

        $requiredApprovals = $stepConfig['required_approvals'] ?? 1;
        $decisions = $this->decisions()
            ->where('step_number', $this->step_number)
            ->where('decision', '!=', 'rejected')
            ->count();

        return $decisions >= $requiredApprovals;
    }

    /**
     * Check if current step has been rejected
     */
    public function isCurrentStepRejected(): bool
    {
        return $this->decisions()
            ->where('step_number', $this->step_number)
            ->where('decision', 'rejected')
            ->exists();
    }

    /**
     * Check if workflow has timed out
     */
    public function isTimedOut(): bool
    {
        return $this->timeout_at !== null
            && now()->greaterThan($this->timeout_at)
            && $this->isInProgress();
    }

    /**
     * Check if workflow was auto-rejected due to timeout
     */
    public function isAutoRejected(): bool
    {
        return $this->auto_rejected_at !== null;
    }
}
