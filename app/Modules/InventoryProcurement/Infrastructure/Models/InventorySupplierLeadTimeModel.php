<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventorySupplierLeadTimeModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_supplier_lead_times';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'supplier_id',
        'item_id',
        'procurement_request_id',
        'order_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'expected_lead_time_days',
        'actual_lead_time_days',
        'quantity_ordered',
        'quantity_received',
        'fulfillment_rate',
        'delivery_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'actual_delivery_date' => 'date',
            'quantity_ordered' => 'decimal:3',
            'quantity_received' => 'decimal:3',
            'fulfillment_rate' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
