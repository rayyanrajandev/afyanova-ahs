<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingCorporateAccountModel extends Model
{
    use HasUuids;

    protected $table = 'billing_corporate_accounts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'billing_payer_contract_id',
        'account_code',
        'account_name',
        'billing_contact_name',
        'billing_contact_email',
        'billing_contact_phone',
        'billing_cycle_day',
        'settlement_terms_days',
        'status',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle_day' => 'integer',
            'settlement_terms_days' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
