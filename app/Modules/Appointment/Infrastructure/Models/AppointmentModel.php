<?php

namespace App\Modules\Appointment\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppointmentModel extends Model
{
    use HasUuids;

    protected $table = 'appointments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'appointment_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'source_admission_id',
        'clinician_user_id',
        'department',
        'scheduled_at',
        'duration_minutes',
        'reason',
        'notes',
        'appointment_type',
        'consultation_type',
        'consultation_type_source',
        'consultation_type_override_reason',
        'prior_completed_appointment_id',
        'financial_coverage_type',
        'billing_payer_contract_id',
        'coverage_reference',
        'coverage_notes',
        'status',
        'status_reason',
        'queue_position',
        'checked_in_at',
        'triage_vitals_summary',
        'triage_notes',
        'triage_category',
        'triaged_at',
        'triaged_by_user_id',
        'triage_owner_user_id',
        'triage_owner_assigned_at',
        'consultation_started_at',
        'consultation_owner_user_id',
        'consultation_owner_assigned_at',
        'consultation_takeover_count',
        'nursing_contact_user_id',
        'nursing_contact_started_at',
        // Relationship form of `department` (2026_08_16_000004). The string column
        // stays as a denormalised label until its readers move over.
        'department_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'triaged_at' => 'datetime',
            'triage_owner_assigned_at' => 'datetime',
            'consultation_started_at' => 'datetime',
            'consultation_owner_assigned_at' => 'datetime',
            'nursing_contact_started_at' => 'datetime',
            'consultation_takeover_count' => 'integer',
            'queue_position' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
