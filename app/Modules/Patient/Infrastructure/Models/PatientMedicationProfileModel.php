<?php

namespace App\Modules\Patient\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PatientMedicationProfileModel extends Model
{
    use HasUuids;

    protected $table = 'patient_medication_profiles';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'patient_id',
        'tenant_id',
        'medication_code',
        'medication_name',
        'dose',
        'route',
        'frequency',
        'source',
        'status',
        'started_at',
        'stopped_at',
        'indication',
        'notes',
        'last_reconciled_at',
        'reconciliation_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'stopped_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
