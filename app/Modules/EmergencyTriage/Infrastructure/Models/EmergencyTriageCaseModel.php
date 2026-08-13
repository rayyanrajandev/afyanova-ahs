<?php

namespace App\Modules\EmergencyTriage\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Emergency Department triage only — a separate, structurally unrelated
 * system from OPD triage despite the shared word "triage". OPD triage is
 * recorded via App\Modules\Appointment\Application\UseCases\RecordAppointmentTriageUseCase
 * and rendered at resources/js/pages/triage/Queue.vue; it has no code-level
 * relationship to this module. Do not assume the two are interchangeable.
 */
class EmergencyTriageCaseModel extends Model
{
    use HasUuids;

    protected $table = 'emergency_triage_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'case_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'admission_id',
        'appointment_id',
        'assigned_clinician_user_id',
        'arrived_at',
        'triage_level',
        'chief_complaint',
        'vitals_summary',
        'triaged_at',
        'disposition_notes',
        'completed_at',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'triaged_at' => 'datetime',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
