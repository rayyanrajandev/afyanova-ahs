<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApprovalDecisionModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_approval_decisions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'workflow_instance_id',
        'approver_user_id',
        'step_number',
        'step_type',
        'decision',
        'comments',
        'approver_department_id',
        'approver_job_title',
        'requisition_requester_id',
        'sod_violation_flagged',
        'sod_violation_reason',
        'decided_at',
        'escalated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sod_violation_flagged' => 'boolean',
            'decided_at' => 'datetime',
            'escalated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the workflow instance
     */
    public function workflowInstance(): BelongsTo
    {
        return $this->belongsTo(
            InventoryApprovalWorkflowInstanceModel::class,
            'workflow_instance_id',
            'id'
        );
    }

    /**
     * Get the approver (user who made the decision)
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id', 'id');
    }

    /**
     * Get the requisition requester (user who created the requisition)
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requisition_requester_id', 'id');
    }

    /**
     * Check if decision was approved
     */
    public function isApproved(): bool
    {
        return $this->decision === 'approved';
    }

    /**
     * Check if decision was rejected
     */
    public function isRejected(): bool
    {
        return $this->decision === 'rejected';
    }

    /**
     * Check if decision was recalled
     */
    public function isRecalled(): bool
    {
        return $this->decision === 'recalled';
    }

    /**
     * Check if SOD violation occurred
     */
    public function hasSodViolation(): bool
    {
        return $this->sod_violation_flagged === true;
    }

    /**
     * Get SOD violation details
     */
    public function getSodViolationDetails(): ?array
    {
        if (!$this->hasSodViolation()) {
            return null;
        }

        return [
            'violation_type' => 'requester_is_approver',
            'requester_id' => $this->requisition_requester_id,
            'approver_id' => $this->approver_user_id,
            'reason' => $this->sod_violation_reason,
        ];
    }

/**
 * Check if decision was escalated
 */
public function isEscalated(): bool
{
    return $this->escalated_at !== null;
}

/**
 * Prevent updates to decision records (immutability enforcement)
 *
 * @throws \LogicException
 */
public function update(array $attributes = [], array $options = []): bool
{
    throw new \LogicException('Approval decision records are immutable and cannot be updated.');
}

/**
 * Prevent deletion of decision records (immutability enforcement)
 *
 * @throws \LogicException
 */
public function delete(): bool
{
    throw new \LogicException('Approval decision records are immutable and cannot be deleted.');
}
}
