<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClinicalOrderSession extends Model
{
    use HasUuids;

    protected $table = 'clinical_order_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'session_number',
        'module',
        'tenant_id',
        'facility_id',
        'patient_id',
        'admission_id',
        'appointment_id',
        'ordered_by_user_id',
        'submitted_at',
        'item_count',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'item_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
