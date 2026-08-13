<?php

namespace App\Modules\InventoryProcurement\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class InventoryDispensingClaimLinkModel extends Model
{
    use HasUuids;

    protected $table = 'inventory_dispensing_claim_links';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'stock_movement_id',
        'pharmacy_order_id',
        'item_id',
        'batch_id',
        'internal_batch_number',
        'quantity_dispensed',
        'unit',
        'unit_cost',
        'total_cost',
        'patient_id',
        'admission_id',
        'appointment_id',
        'insurance_claim_id',
        'billing_invoice_id',
        'nhif_code',
        'payer_type',
        'payer_name',
        'payer_reference',
        'claim_status',
        'submitted_at',
        'adjudicated_at',
        'approved_amount',
        'rejected_amount',
        'rejection_reason',
        'metadata',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_dispensed' => 'decimal:3',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'approved_amount' => 'decimal:4',
            'rejected_amount' => 'decimal:4',
            'submitted_at' => 'datetime',
            'adjudicated_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
