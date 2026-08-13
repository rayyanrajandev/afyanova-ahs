<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPaymentGatewayTransactionModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payment_gateway_transactions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'billing_invoice_id',
        'billing_invoice_payment_id',
        'gateway',
        'transaction_reference',
        'provider_reference',
        'phone_number',
        'amount',
        'currency',
        'status',
        'description',
        'request_payload',
        'response_payload',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
