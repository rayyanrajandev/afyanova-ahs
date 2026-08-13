<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TheatreProcedureResourceAllocationModel extends Model
{
    use HasUuids;

    protected $table = 'theatre_procedure_resource_allocations';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'theatre_procedure_id',
        'tenant_id',
        'facility_id',
        'resource_type',
        'resource_reference',
        'role_label',
        'planned_start_at',
        'planned_end_at',
        'actual_start_at',
        'actual_end_at',
        'status',
        'status_reason',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
