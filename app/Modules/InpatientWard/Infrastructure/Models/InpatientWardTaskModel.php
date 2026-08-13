<?php

namespace App\Modules\InpatientWard\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InpatientWardTaskModel extends Model
{
    use HasUuids;

    protected $table = 'inpatient_ward_tasks';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'task_number',
        'tenant_id',
        'facility_id',
        'admission_id',
        'patient_id',
        'task_type',
        'title',
        'priority',
        'status',
        'status_reason',
        'assigned_to_user_id',
        'created_by_user_id',
        'due_at',
        'started_at',
        'completed_at',
        'escalated_at',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
