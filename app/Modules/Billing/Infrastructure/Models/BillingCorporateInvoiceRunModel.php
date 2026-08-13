<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCorporateInvoiceRunModel extends Model
{
    use HasUuids;

    protected $table = 'billing_corporate_invoice_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_corporate_account_id',
        'tenant_id',
        'facility_id',
        'run_number',
        'billing_period_start',
        'billing_period_end',
        'issue_date',
        'due_date',
        'currency_code',
        'invoice_count',
        'total_amount',
        'paid_amount',
        'balance_amount',
        'last_payment_at',
        'status',
        'notes',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'last_payment_at' => 'datetime',
            'invoice_count' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
