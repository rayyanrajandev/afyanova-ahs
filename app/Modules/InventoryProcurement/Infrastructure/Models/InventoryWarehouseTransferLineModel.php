<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryWarehouseTransferLineModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_warehouse_transfer_lines';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'transfer_id',
        'item_id',
        'batch_id',
        'internal_batch_number',
        'requested_quantity',
        'packed_quantity',
        'dispatched_quantity',
        'received_quantity',
        'reported_received_quantity',
        'receipt_variance_type',
        'receipt_variance_quantity',
        'receipt_variance_reason',
        'unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3',
            'packed_quantity' => 'decimal:3',
            'dispatched_quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'reported_received_quantity' => 'decimal:3',
            'receipt_variance_quantity' => 'decimal:3',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryStockReservationModel::class, 'source_line_id')
            ->where('source_type', 'inventory_warehouse_transfer');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItemModel::class, 'item_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatchModel::class, 'batch_id');
    }
}
