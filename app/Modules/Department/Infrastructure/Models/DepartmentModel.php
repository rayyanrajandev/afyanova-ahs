<?php

namespace App\Modules\Department\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DepartmentModel extends Model
{
    use HasUuids;

    protected $table = 'departments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'service_type',
        'is_patient_facing',
        'is_appointmentable',
        'manager_user_id',
        'status',
        'status_reason',
        'description',
        'default_warehouse_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_patient_facing' => 'boolean',
            'is_appointmentable' => 'boolean',
            'manager_user_id' => 'integer',
            'default_warehouse_id' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

