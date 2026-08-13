<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingNhifVerificationModel extends Model
{
    use HasUuids;

    protected $table = 'billing_nhif_verifications';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'patient_insurance_record_id',
        'member_id',
        'card_status',
        'is_active',
        'member_name',
        'plan_name',
        'employer_name',
        'effective_date',
        'expiry_date',
        'outstanding_balance',
        'dependants',
        'raw_response',
        'source',
        'verified_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'outstanding_balance' => 'decimal:2',
            'dependants' => 'array',
            'raw_response' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
