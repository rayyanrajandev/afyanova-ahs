<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MultiFacilityRolloutPlanModel extends Model
{
    use HasUuids;

    protected $table = 'facility_rollout_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'rollout_code',
        'status',
        'target_go_live_at',
        'actual_go_live_at',
        'owner_user_id',
        'rollback_required',
        'rollback_reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_go_live_at' => 'datetime',
            'actual_go_live_at' => 'datetime',
            'rollback_required' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
