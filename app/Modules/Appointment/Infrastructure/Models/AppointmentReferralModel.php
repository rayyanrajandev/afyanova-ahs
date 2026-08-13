<?php

namespace App\Modules\Appointment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppointmentReferralModel extends Model
{
    use HasUuids;

    protected $table = 'appointment_referrals';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'appointment_id',
        'referral_number',
        'tenant_id',
        'facility_id',
        'referral_type',
        'priority',
        'target_department',
        'target_facility_id',
        'target_facility_code',
        'target_facility_name',
        'target_clinician_user_id',
        'referral_reason',
        'clinical_notes',
        'handoff_notes',
        'requested_at',
        'accepted_at',
        'handed_off_at',
        'completed_at',
        'status',
        'status_reason',
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
            'handed_off_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
