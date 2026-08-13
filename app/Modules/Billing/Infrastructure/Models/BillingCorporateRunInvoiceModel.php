<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCorporateRunInvoiceModel extends Model
{
    use HasUuids;

    protected $table = 'billing_corporate_run_invoices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_corporate_invoice_run_id',
        'billing_invoice_id',
        'patient_id',
        'invoice_number',
        'patient_display_name',
        'invoice_date',
        'invoice_total_amount',
        'included_amount',
        'paid_amount',
        'outstanding_amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'invoice_total_amount' => 'decimal:2',
            'included_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
