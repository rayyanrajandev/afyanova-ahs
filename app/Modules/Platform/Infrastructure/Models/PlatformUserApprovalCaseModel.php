<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlatformUserApprovalCaseModel extends Model
{
    use HasUuids;

    protected $table = 'platform_user_approval_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'target_user_id',
        'requester_user_id',
        'reviewer_user_id',
        'case_reference',
        'action_type',
        'action_payload',
        'status',
        'decision_reason',
        'submitted_at',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_user_id' => 'integer',
            'requester_user_id' => 'integer',
            'reviewer_user_id' => 'integer',
            'action_payload' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

