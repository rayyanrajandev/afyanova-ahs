<?php

namespace App\Modules\PatientFlow\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only — see the table's migration for why. There is deliberately no
 * update path: $timestamps is false (the log carries occurred_at, which is
 * when the step actually changed, not when the row was inserted), and nothing
 * in this module calls save() on a loaded instance. A correction is a new
 * event.
 */
class PatientFlowEventModel extends Model
{
    use HasUuids;

    protected $table = 'patient_flow_events';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'service_request_id',
        'encounter_id',
        'from_step',
        'to_step',
        'actor_user_id',
        'actor_role',
        'source',
        'reason',
        'metadata',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
