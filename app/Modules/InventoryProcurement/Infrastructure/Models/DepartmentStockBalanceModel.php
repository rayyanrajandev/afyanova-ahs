<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentStockBalanceModel extends Model
{
    use HasUuids;

    protected $table = 'department_stock_balances';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'item_id',
        'batch_id',
        'internal_batch_number',
        'quantity_on_hand',
        'quantity_consumed',
        'quantity_returned',
        'quantity_wasted',
        'unit',
        'last_issued_at',
        'last_consumed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:3',
            'quantity_consumed' => 'decimal:3',
            'quantity_returned' => 'decimal:3',
            'quantity_wasted' => 'decimal:3',
            'last_issued_at' => 'datetime',
            'last_consumed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
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
