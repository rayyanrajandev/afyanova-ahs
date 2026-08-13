<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingTraReceiptModel extends Model
{
    use HasUuids;

    protected $table = 'billing_tra_receipts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'billing_invoice_id',
        'billing_invoice_payment_id',
        'reference_number',
        'rctvnum',
        'verification_link',
        'local_date',
        'local_time',
        'gc',
        'dc',
        'z_number',
        'total_incl_tax',
        'total_tax',
        'raw_response',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'gc' => 'integer',
            'dc' => 'integer',
            'total_incl_tax' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'raw_response' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
