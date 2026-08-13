<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryWarehouseModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_warehouses';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'warehouse_code',
        'warehouse_name',
        'warehouse_type',
        'location',
        'contact_person',
        'phone',
        'email',
        'is_default',
        'status',
        'status_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}

