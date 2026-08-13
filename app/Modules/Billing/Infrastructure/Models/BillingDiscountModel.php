<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingDiscountModel extends Model
{
    use HasUuids;

    protected $table = 'billing_discounts';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_invoice_id',
        'billing_discount_policy_id',
        'original_amount',
        'discount_amount',
        'final_amount',
        'applied_by_user_id',
        'applied_at',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the invoice this discount was applied to
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoiceModel::class, 'billing_invoice_id', 'id');
    }

    /**
     * Get the discount policy this was based on
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(BillingDiscountPolicyModel::class, 'billing_discount_policy_id', 'id');
    }
}
