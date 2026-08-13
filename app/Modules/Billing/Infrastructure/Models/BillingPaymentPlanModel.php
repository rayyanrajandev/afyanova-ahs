<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPaymentPlanModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payment_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'billing_invoice_id',
        'cash_billing_account_id',
        'plan_number',
        'plan_name',
        'currency_code',
        'total_amount',
        'down_payment_amount',
        'financed_amount',
        'paid_amount',
        'balance_amount',
        'installment_count',
        'installment_frequency',
        'installment_interval_days',
        'first_due_date',
        'next_due_date',
        'last_payment_at',
        'status',
        'terms_and_notes',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'down_payment_amount' => 'decimal:2',
            'financed_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance_amount' => 'decimal:2',
            'installment_count' => 'integer',
            'installment_interval_days' => 'integer',
            'first_due_date' => 'date',
            'next_due_date' => 'date',
            'last_payment_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
