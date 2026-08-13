<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MultiFacilityRolloutIncidentModel extends Model
{
    use HasUuids;

    protected $table = 'facility_rollout_incidents';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'rollout_plan_id',
        'incident_code',
        'severity',
        'status',
        'summary',
        'details',
        'escalated_to',
        'opened_by_user_id',
        'resolved_by_user_id',
        'opened_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
