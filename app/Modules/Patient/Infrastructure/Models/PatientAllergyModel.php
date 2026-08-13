<?php

namespace App\Modules\Patient\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PatientAllergyModel extends Model
{
    use HasUuids;

    protected $table = 'patient_allergies';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'patient_id',
        'tenant_id',
        'substance_code',
        'substance_name',
        'reaction',
        'severity',
        'status',
        'noted_at',
        'last_reaction_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'noted_at' => 'datetime',
            'last_reaction_at' => 'date:Y-m-d',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
