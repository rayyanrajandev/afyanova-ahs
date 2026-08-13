<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryWarehouseTransferAuditLogModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_warehouse_transfer_audit_logs';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        'transfer_id',
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
