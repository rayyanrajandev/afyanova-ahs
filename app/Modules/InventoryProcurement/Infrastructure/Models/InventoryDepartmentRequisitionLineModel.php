<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryDepartmentRequisitionLineModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_department_requisition_lines';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'requisition_id',
        'item_id',
        'batch_id',
        'requested_quantity',
        'approved_quantity',
        'issued_quantity',
        'unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3',
            'approved_quantity' => 'decimal:3',
            'issued_quantity' => 'decimal:3',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
