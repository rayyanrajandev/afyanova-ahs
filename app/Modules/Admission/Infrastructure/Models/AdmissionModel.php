<?php

namespace App\Modules\Admission\Infrastructure\Models;

use App\Modules\Patient\Infrastructure\Models\PatientModel;
use App\Modules\Platform\Infrastructure\Models\FacilityResourceModel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionModel extends Model
{
    use HasUuids;

    protected $table = 'admissions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'admission_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'appointment_id',
        'attending_clinician_user_id',
        'ward',
        'bed',
        'bed_resource_id',
        'admitted_at',
        'discharged_at',
        'admission_reason',
        'notes',
        'financial_coverage_type',
        'billing_payer_contract_id',
        'coverage_reference',
        'coverage_notes',
        'status',
        'status_reason',
        'discharge_destination',
        'follow_up_plan',
    ];

    public function patient(): BelongsTo
    {
        return $this->belongsTo(PatientModel::class, 'patient_id');
    }

    public function bedResource(): BelongsTo
    {
        return $this->belongsTo(FacilityResourceModel::class, 'bed_resource_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admitted_at' => 'datetime',
            'discharged_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
