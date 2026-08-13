<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformUserAdminAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'platform_user_admin_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'actor_id',
        'target_user_id',
        'action',
        'changes',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_user_id' => 'integer',
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

