<?php

namespace App\Modules\Revenue\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class RevenueTelemetryEventModel extends Model
{
    use HasUuids;

    protected $table = 'revenue_telemetry_events';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'event_type',
        'reason',
        'source_kind',
        'source_workflow_id',
        'service_charge_id',
        'patient_id',
        'actor_user_id',
        'detail',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_user_id' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
