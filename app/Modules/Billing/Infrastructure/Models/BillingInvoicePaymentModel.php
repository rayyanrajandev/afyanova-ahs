<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingInvoicePaymentModel extends Model
{
    use HasUuids;

    protected $table = 'billing_invoice_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_invoice_id',
        'recorded_by_user_id',
        'payment_at',
        'amount',
        'cumulative_paid_amount',
        'entry_type',
        'reversal_of_payment_id',
        'reversal_reason',
        'approval_case_reference',
        'payer_type',
        'payment_method',
        'payment_reference',
        'source_action',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_at' => 'datetime',
            'amount' => 'decimal:2',
            'cumulative_paid_amount' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
