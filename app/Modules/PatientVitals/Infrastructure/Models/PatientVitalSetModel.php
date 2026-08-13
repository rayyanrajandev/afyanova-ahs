<?php

namespace App\Modules\PatientVitals\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PatientVitalSetModel extends Model
{
    use HasUuids;

    protected $table = 'patient_vital_sets';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'patient_id',
        'admission_id',
        'appointment_id',
        'recorded_by_user_id',
        'recorded_at',
        'temperature_c',
        'heart_rate_bpm',
        'systolic_bp_mmhg',
        'diastolic_bp_mmhg',
        'oxygen_saturation_pct',
        'respiratory_rate_bpm',
        'weight_kg',
        'entry_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }
}
