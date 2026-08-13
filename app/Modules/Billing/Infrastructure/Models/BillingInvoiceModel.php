<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingInvoiceModel extends Model
{
    use HasUuids;

    protected $table = 'billing_invoices';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'source_cash_billing_account_id',
        'admission_id',
        'appointment_id',
        'billing_payer_contract_id',
        'issued_by_user_id',
        'invoice_date',
        'currency_code',
        'subtotal_amount',
        'discount_amount',
        'tax_amount',
        'total_amount',
        'paid_amount',
        'last_payment_at',
        'last_payment_payer_type',
        'last_payment_method',
        'last_payment_reference',
        'balance_amount',
        'payment_due_at',
        'notes',
        'line_items',
        'adjustments',
        'pricing_mode',
        'pricing_context',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'datetime',
            'subtotal_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'last_payment_at' => 'datetime',
            'balance_amount' => 'decimal:2',
            'payment_due_at' => 'datetime',
            'line_items' => 'array',
            'adjustments' => 'array',
            'pricing_context' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function lineItemSources(): HasMany
    {
        return $this->hasMany(BillingInvoiceLineItemSourceModel::class, 'billing_invoice_id');
    }
}
