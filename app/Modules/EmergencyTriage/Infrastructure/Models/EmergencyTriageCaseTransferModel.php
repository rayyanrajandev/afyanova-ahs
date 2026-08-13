<?php

namespace App\Modules\EmergencyTriage\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmergencyTriageCaseTransferModel extends Model
{
    use HasUuids;

    protected $table = 'emergency_triage_case_transfers';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'emergency_triage_case_id',
        'transfer_number',
        'tenant_id',
        'facility_id',
        'transfer_type',
        'priority',
        'source_location',
        'destination_location',
        'destination_facility_name',
        'accepting_clinician_user_id',
        'requested_at',
        'accepted_at',
        'departed_at',
        'arrived_at',
        'completed_at',
        'status',
        'status_reason',
        'clinical_handoff_notes',
        'transport_mode',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'accepted_at' => 'datetime',
            'departed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
