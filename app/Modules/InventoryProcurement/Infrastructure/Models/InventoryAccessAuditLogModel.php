<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAccessAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_access_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'department_id',
        'action',
        'actor_id',
        'actor_department',
        'action_timestamp',
        'resource_type',
        'resource_id',
        'resource_name',
        'target_user_id',
        'target_role_id',
        'before_state',
        'after_state',
        'changes',
        'business_context',
        'access_decision',
        'deny_reason',
        'permissions_checked',
        'compliance_flags',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'tenant_id' => 'string',
            'facility_id' => 'string',
            'department_id' => 'string',
            'actor_id' => 'string',
            'target_user_id' => 'string',
            'target_role_id' => 'string',
            'action_timestamp' => 'datetime',
            'before_state' => 'array',
            'after_state' => 'array',
            'changes' => 'array',
            'business_context' => 'array',
            'permissions_checked' => 'array',
            'compliance_flags' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // Immutable: Only created_at, no updated_at
    public $timestamps = false;

    /**
     * Actor user relationship
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id', 'id');
    }

    /**
     * Target user relationship (for user-related audit logs)
     *
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id', 'id');
    }

    /**
     * Scope: Recent logs (default 30 days)
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $days
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('action_timestamp', '>=', now()->subDays($days));
    }

    /**
     * Scope: Access denials only
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDenials($query)
    {
        return $query->where('access_decision', 'deny');
    }

    /**
     * Scope: Specific action
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $action
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope: For specific resource
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $resourceType
     * @param string $resourceId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeForResource($query, string $resourceType, string $resourceId)
    {
        return $query
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId);
    }

    /**
     * Scope: For specific actor
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $actorId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByActor($query, string $actorId)
    {
        return $query->where('actor_id', $actorId);
    }

    /**
     * Scope: Compliance violations
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeComplianceViolations($query)
    {
        return $query->where('compliance_flags->segregation_check', 'failed');
    }
}
