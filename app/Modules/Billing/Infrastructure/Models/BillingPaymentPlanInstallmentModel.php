<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPaymentPlanInstallmentModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payment_plan_installments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_payment_plan_id',
        'installment_number',
        'due_date',
        'scheduled_amount',
        'paid_amount',
        'outstanding_amount',
        'paid_at',
        'status',
        'source_billing_invoice_payment_id',
        'source_cash_billing_payment_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'scheduled_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
