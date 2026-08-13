<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockMovementModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_stock_movements';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'item_id',
        'batch_id',
        'internal_batch_number',
        'procurement_request_id',
        'source_supplier_id',
        'source_warehouse_id',
        'destination_warehouse_id',
        'destination_department_id',
        'source_type',
        'source_id',
        'clinical_catalog_item_id',
        'consumption_recipe_item_id',
        'movement_type',
        'adjustment_direction',
        'quantity',
        'quantity_delta',
        'stock_before',
        'stock_after',
        'reason',
        'reason_code',
        'notes',
        'actor_id',
        'metadata',
        'is_opening_stock',
        'superseded_by_id',
        'approval_status',
        'approved_at',
        'approved_by_id',
        'occurred_at',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'quantity_delta' => 'decimal:3',
            'stock_before' => 'decimal:3',
            'stock_after' => 'decimal:3',
            'metadata' => 'array',
            'is_opening_stock' => 'boolean',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItemModel::class, 'item_id');
    }
}
