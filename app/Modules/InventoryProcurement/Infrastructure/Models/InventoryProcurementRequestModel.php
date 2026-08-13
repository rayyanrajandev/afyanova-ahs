<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryProcurementRequestModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_procurement_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'request_number',
        'purchase_order_number',
        'tenant_id',
        'facility_id',
        'supplier_id',
        'requesting_department_id',
        'source_department_requisition_id',
        'source_department_requisition_line_id',
        'receiving_warehouse_id',
        'item_id',
        'requested_quantity',
        'ordered_quantity',
        'received_quantity',
        'unit_cost_estimate',
        'received_unit_cost',
        'total_cost_estimate',
        'requested_by_user_id',
        'approved_by_user_id',
        'status',
        'status_reason',
        'needed_by',
        'supplier_name',
        'approval_route',
        'is_catalog_item',
        'duplicate_check_hash',
        'approved_at',
        'ordered_at',
        'received_at',
        'notes',
        'receiving_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requested_quantity' => 'decimal:3',
            'ordered_quantity' => 'decimal:3',
            'received_quantity' => 'decimal:3',
            'unit_cost_estimate' => 'decimal:2',
            'received_unit_cost' => 'decimal:2',
            'total_cost_estimate' => 'decimal:2',
            'needed_by' => 'date',
            'is_catalog_item' => 'boolean',
            'approved_at' => 'datetime',
            'ordered_at' => 'datetime',
            'received_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
