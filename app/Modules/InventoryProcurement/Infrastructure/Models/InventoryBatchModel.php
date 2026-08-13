<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryBatchModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_batches';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'item_id',
        'internal_batch_number',
        'batch_number',
        'lot_number',
        'manufacture_date',
        'expiry_date',
        'quantity',
        'warehouse_id',
        'bin_location',
        'supplier_id',
        'manufacturer',
        'unit_cost',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'manufacture_date' => 'date',
            'expiry_date' => 'date',
            'quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
