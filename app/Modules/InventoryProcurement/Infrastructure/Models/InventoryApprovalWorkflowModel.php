<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryApprovalWorkflowModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_approval_workflows';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'code',
        'name',
        'description',
        'version',
        'trigger_type',
        'trigger_rules',
        'approval_steps',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_rules' => 'json',
            'approval_steps' => 'json',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get workflow instances
     */
    public function instances(): HasMany
    {
        return $this->hasMany(
            InventoryApprovalWorkflowInstanceModel::class,
            'workflow_id',
            'id'
        );
    }

    /**
     * Get approval rules for this workflow
     */
    public function rules(): HasMany
    {
        return $this->hasMany(
            InventoryApprovalRuleModel::class,
            'workflow_id',
            'id'
        );
    }

    /**
     * Get version changes for this workflow
     */
    public function versionChanges(): HasMany
    {
        return $this->hasMany(
            InventoryApprovalWorkflowVersionChangeModel::class,
            'workflow_id',
            'id'
        )->orderBy('version_number', 'desc');
    }

    /**
     * Bump version and log a change record
     *
     * @param array<string, mixed>|null $beforeState
     * @param array<string, mixed>|null $afterState
     */
    public function bumpVersion(
        string $changeType,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $changedByUserId = null,
        ?string $changeReason = null
    ): void {
        $this->increment('version');

        $this->versionChanges()->create([
            'version_number' => $this->version,
            'change_type' => $changeType,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'changed_by_user_type' => $changedByUserId ? 'user' : 'system',
            'changed_by_user_id' => $changedByUserId,
            'change_reason' => $changeReason,
            'changed_at' => now(),
        ]);
    }

    /**
     * Check if workflow is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Parse approval steps configuration
     *
     * @return array<int, array<string, mixed>>
     */
    public function getApprovalSteps(): array
    {
        return $this->approval_steps ?? [];
    }

    /**
     * Get step configuration by step number
     *
     * @param int $stepNumber
     * @return array<string, mixed>|null
     */
    public function getStep(int $stepNumber): ?array
    {
        $steps = $this->getApprovalSteps();
        return $steps[$stepNumber - 1] ?? null;
    }

    /**
     * Parse trigger rules configuration
     *
     * @return array<string, mixed>
     */
    public function getTriggerRules(): array
    {
        return $this->trigger_rules ?? [];
    }
}
