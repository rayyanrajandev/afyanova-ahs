<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingRefundModel extends Model
{
    use HasUuids;

    protected $table = 'billing_refunds';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_invoice_id',
        'billing_invoice_payment_id',
        'patient_id',
        'refund_reason',
        'refund_amount',
        'refund_method',
        'mobile_money_provider',
        'mobile_money_reference',
        'card_reference',
        'check_number',
        'requested_by_user_id',
        'requested_at',
        'approved_by_user_id',
        'approved_at',
        'processed_by_user_id',
        'processed_at',
        'refund_status',
        'rejection_reason',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refund_amount' => 'decimal:2',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the invoice this refund is for
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoiceModel::class, 'billing_invoice_id', 'id');
    }

    /**
     * Get the payment this refund is for
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(BillingInvoicePaymentModel::class, 'billing_invoice_payment_id', 'id');
    }

    /**
     * Get audit logs for this refund
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(BillingRefundAuditLogModel::class, 'billing_refund_id', 'id');
    }
}
