<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CrossTenantAdminAuditLogHoldModel extends Model
{
    use HasUuids;

    protected $table = 'platform_cross_tenant_admin_audit_log_holds';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'hold_code',
        'reason',
        'approval_case_reference',
        'target_tenant_code',
        'action',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by_user_id',
        'approved_by_user_id',
        'review_due_at',
        'released_at',
        'released_by_user_id',
        'release_reason',
        'release_case_reference',
        'release_approved_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'review_due_at' => 'datetime',
            'released_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
