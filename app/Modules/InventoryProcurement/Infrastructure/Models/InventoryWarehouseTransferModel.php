<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryWarehouseTransferModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_warehouse_transfers';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'transfer_number',
        'source_warehouse_id',
        'destination_warehouse_id',
        'status',
        'priority',
        'reason',
        'rejection_reason',
        'requested_by_user_id',
        'approved_by_user_id',
        'packed_by_user_id',
        'dispatched_by_user_id',
        'received_by_user_id',
        'receipt_variance_reviewed_by_user_id',
        'approved_at',
        'packed_at',
        'dispatched_at',
        'received_at',
        'receipt_variance_review_status',
        'receipt_variance_reviewed_at',
        'dispatch_note_number',
        'notes',
        'pack_notes',
        'receiving_notes',
        'receipt_variance_review_notes',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'packed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'receipt_variance_reviewed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryWarehouseTransferLineModel::class, 'transfer_id');
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouseModel::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(InventoryWarehouseModel::class, 'destination_warehouse_id');
    }
}
