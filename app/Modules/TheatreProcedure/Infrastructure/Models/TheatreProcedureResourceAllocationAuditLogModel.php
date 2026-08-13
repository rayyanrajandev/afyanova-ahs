<?php

namespace App\Modules\TheatreProcedure\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TheatreProcedureResourceAllocationAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'theatre_procedure_resource_allocation_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'theatre_procedure_resource_allocation_id',
        'theatre_procedure_id',
        'action',
        'actor_id',
        'changes',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
