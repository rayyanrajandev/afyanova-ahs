<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class InventoryItemUnitModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_item_units';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'item_id',
        'unit_name',
        'unit_code',
        'base_quantity',
        'is_base_unit',
        'is_default_sales_unit',
        'is_default_purchase_unit',
        'is_active',
        'barcode',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_quantity' => 'decimal:6',
            'is_base_unit' => 'boolean',
            'is_default_sales_unit' => 'boolean',
            'is_default_purchase_unit' => 'boolean',
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

    /**
     * @return HasMany<int, InventoryItemUnitPriceModel>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(InventoryItemUnitPriceModel::class, 'inventory_item_unit_id');
    }
}