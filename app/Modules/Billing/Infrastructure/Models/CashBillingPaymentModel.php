<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashBillingPaymentModel extends Model
{
    use HasUuids;

    protected $table = 'cash_billing_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'cash_billing_account_id',
        'amount_paid',
        'currency_code',
        'payment_method',
        'payment_reference',
        'mobile_money_provider',
        'mobile_money_transaction_id',
        'card_last_four',
        'check_number',
        'paid_at',
        'confirmed_by_user_id',
        'receipt_number',
        'notes',
        'refunded_amount',
        'refunded_at',
        'refunded_by_user_id',
        'refund_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the cash billing account this payment belongs to
     */
    public function cashBillingAccount(): BelongsTo
    {
        return $this->belongsTo(CashBillingAccountModel::class, 'cash_billing_account_id', 'id');
    }
}
