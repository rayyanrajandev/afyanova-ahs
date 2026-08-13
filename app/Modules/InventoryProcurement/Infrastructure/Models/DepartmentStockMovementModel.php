<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentStockMovementModel extends Model
{
    use HasUuids;

    protected $table = 'department_stock_movements';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_stock_balance_id',
        'department_id',
        'item_id',
        'batch_id',
        'internal_batch_number',
        'movement_type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'source',
        'source_id',
        'notes',
        'metadata',
        'actor_id',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'quantity_before' => 'decimal:3',
            'quantity_after' => 'decimal:3',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItemModel::class, 'item_id');
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(DepartmentStockBalanceModel::class, 'department_stock_balance_id');
    }
}
