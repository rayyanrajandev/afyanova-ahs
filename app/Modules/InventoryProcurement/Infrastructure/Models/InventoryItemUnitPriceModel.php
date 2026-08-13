<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class InventoryItemUnitPriceModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_item_unit_prices';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'item_id',
        'inventory_item_unit_id',
        'price_type',
        'billing_payer_contract_id',
        'price',
        'currency_code',
        'effective_from',
        'effective_to',
        'is_active',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItemModel::class, 'item_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryItemUnitModel::class, 'inventory_item_unit_id');
    }
}