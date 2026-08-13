<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryDepartmentRequisitionModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_department_requisitions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'requisition_number',
        'tenant_id',
        'facility_id',
        'requesting_department',
        'requesting_department_id',
        'issuing_store',
        'issuing_warehouse_id',
        'priority',
        'status',
        'requested_by_user_id',
        'approved_by_user_id',
        'issued_by_user_id',
        'approved_at',
        'issued_at',
        'needed_by',
        'notes',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'issued_at' => 'datetime',
            'needed_by' => 'date',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the requisition lines
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryDepartmentRequisitionLineModel::class, 'requisition_id', 'id');
    }
}
