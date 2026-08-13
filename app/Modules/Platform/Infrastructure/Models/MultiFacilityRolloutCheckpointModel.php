<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MultiFacilityRolloutCheckpointModel extends Model
{
    use HasUuids;

    protected $table = 'facility_rollout_checkpoints';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'rollout_plan_id',
        'checkpoint_code',
        'checkpoint_name',
        'status',
        'decision_notes',
        'completed_by_user_id',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
