<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CrossTenantAdminAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'platform_cross_tenant_admin_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'action',
        'operation_type',
        'actor_id',
        'target_tenant_id',
        'target_tenant_code',
        'target_resource_type',
        'target_resource_id',
        'outcome',
        'reason',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
