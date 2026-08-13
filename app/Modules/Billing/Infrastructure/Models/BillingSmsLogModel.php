<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingSmsLogModel extends Model
{
    use HasUuids;

    protected $table = 'billing_sms_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'phone_number',
        'message_type',
        'message',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'context',
        'billing_invoice_id',
        'billing_payment_link_id',
        'patient_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
