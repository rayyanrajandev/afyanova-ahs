<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryMsdOrderModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_msd_orders';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'msd_order_number',
        'facility_msd_code',
        'procurement_request_id',
        'supplier_id',
        'order_lines',
        'currency_code',
        'total_amount',
        'order_date',
        'expected_delivery_date',
        'actual_delivery_date',
        'status',
        'submission_reference',
        'submitted_at',
        'confirmed_at',
        'dispatched_at',
        'delivered_at',
        'delivery_note_number',
        'rejection_reason',
        'api_response_log',
        'metadata',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'order_lines' => 'array',
            'total_amount' => 'decimal:4',
            'order_date' => 'date',
            'expected_delivery_date' => 'date',
            'actual_delivery_date' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'api_response_log' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
