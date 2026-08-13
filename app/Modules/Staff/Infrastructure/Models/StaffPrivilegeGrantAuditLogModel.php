<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffPrivilegeGrantAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'staff_privilege_grant_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'staff_privilege_grant_id',
        'staff_profile_id',
        'action',
        'actor_id',
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
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}

