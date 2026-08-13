<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryDepartmentRequisitionAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_department_requisition_audit_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'requisition_id',
        'action',
        'actor_type',
        'actor_id',
        'changes',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
