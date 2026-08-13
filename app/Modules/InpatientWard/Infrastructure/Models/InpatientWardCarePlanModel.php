<?php

namespace App\Modules\InpatientWard\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InpatientWardCarePlanModel extends Model
{
    use HasUuids;

    protected $table = 'inpatient_ward_care_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'care_plan_number',
        'tenant_id',
        'facility_id',
        'admission_id',
        'patient_id',
        'title',
        'plan_text',
        'goals',
        'interventions',
        'target_discharge_at',
        'review_due_at',
        'status',
        'status_reason',
        'author_user_id',
        'last_updated_by_user_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goals' => 'array',
            'interventions' => 'array',
            'target_discharge_at' => 'datetime',
            'review_due_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

