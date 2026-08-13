<?php

namespace App\Modules\MedicalRecord\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MedicalRecordVersionModel extends Model
{
    use HasUuids;

    protected $table = 'medical_record_versions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'medical_record_id',
        'version_number',
        'snapshot',
        'changed_fields',
        'created_by_user_id',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changed_fields' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
