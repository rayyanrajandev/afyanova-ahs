<?php

namespace App\Modules\InpatientWard\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InpatientWardRoundNoteModel extends Model
{
    use HasUuids;

    protected $table = 'inpatient_ward_round_notes';

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
        'author_user_id',
        'rounded_at',
        'shift_label',
        'round_note',
        'care_plan',
        'handoff_notes',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rounded_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
