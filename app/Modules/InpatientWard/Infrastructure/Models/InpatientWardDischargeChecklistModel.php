<?php

namespace App\Modules\InpatientWard\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InpatientWardDischargeChecklistModel extends Model
{
    use HasUuids;

    protected $table = 'inpatient_ward_discharge_checklists';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'admission_id',
        'patient_id',
        'status',
        'status_reason',
        'clinical_summary_completed',
        'medication_reconciliation_completed',
        'follow_up_plan_completed',
        'patient_education_completed',
        'transport_arranged',
        'billing_cleared',
        'documentation_completed',
        'is_ready_for_discharge',
        'last_reviewed_by_user_id',
        'reviewed_at',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clinical_summary_completed' => 'bool',
            'medication_reconciliation_completed' => 'bool',
            'follow_up_plan_completed' => 'bool',
            'patient_education_completed' => 'bool',
            'transport_arranged' => 'bool',
            'billing_cleared' => 'bool',
            'documentation_completed' => 'bool',
            'is_ready_for_discharge' => 'bool',
            'reviewed_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

