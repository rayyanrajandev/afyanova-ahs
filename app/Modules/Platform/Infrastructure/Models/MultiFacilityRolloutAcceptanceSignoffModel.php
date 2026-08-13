<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MultiFacilityRolloutAcceptanceSignoffModel extends Model
{
    use HasUuids;

    protected $table = 'facility_rollout_acceptance_signoffs';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'rollout_plan_id',
        'training_completed_at',
        'acceptance_status',
        'accepted_by_user_id',
        'acceptance_case_reference',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'training_completed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
