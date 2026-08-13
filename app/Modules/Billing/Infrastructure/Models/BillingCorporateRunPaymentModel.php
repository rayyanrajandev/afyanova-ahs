<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCorporateRunPaymentModel extends Model
{
    use HasUuids;

    protected $table = 'billing_corporate_run_payments';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_corporate_invoice_run_id',
        'amount',
        'currency_code',
        'payment_method',
        'payment_reference',
        'paid_at',
        'recorded_by_user_id',
        'note',
        'allocations',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'allocations' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
