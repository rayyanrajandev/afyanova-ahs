<?php

namespace App\Modules\Staff\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffPrivilegeGrantModel extends Model
{
    use HasUuids;

    protected $table = 'staff_privilege_grants';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'staff_profile_id',
        'tenant_id',
        'facility_id',
        'specialty_id',
        'privilege_catalog_id',
        'privilege_code',
        'privilege_name',
        'scope_notes',
        'granted_at',
        'review_due_at',
        'requested_at',
        'review_started_at',
        'approved_at',
        'activated_at',
        'status',
        'status_reason',
        'granted_by_user_id',
        'reviewer_user_id',
        'review_note',
        'approver_user_id',
        'approval_note',
        'updated_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'date',
            'review_due_at' => 'date',
            'requested_at' => 'datetime',
            'review_started_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
            'granted_by_user_id' => 'integer',
            'reviewer_user_id' => 'integer',
            'approver_user_id' => 'integer',
            'updated_by_user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
