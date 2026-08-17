<?php

namespace App\Modules\Encounter\Infrastructure\Models;

use App\Models\User;
use App\Modules\Patient\Infrastructure\Models\PatientModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EncounterModel extends Model
{
    use HasUuids;

    protected $table = 'encounters';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'encounter_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'admission_id',
        'primary_clinician_user_id',
        'status',
        'type',
        'visit_category',
        'opened_at',
        'closed_at',
        'status_reason',
        'disposition',
        'disposition_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function primaryClinician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_clinician_user_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientModel::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Appointment\Infrastructure\Models\AppointmentModel::class, 'appointment_id');
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(EncounterDiagnosisModel::class, 'encounter_id');
    }
}
