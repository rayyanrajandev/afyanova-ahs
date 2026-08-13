<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryUnitConversionModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_unit_conversions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'item_id',
        'from_unit',
        'to_unit',
        'factor',
        'is_global',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'is_global' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
