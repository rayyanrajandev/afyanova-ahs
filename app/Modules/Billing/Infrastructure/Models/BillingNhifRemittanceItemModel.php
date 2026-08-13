<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingNhifRemittanceItemModel extends Model
{
    use HasUuids;

    protected $table = 'billing_nhif_remittance_items';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'billing_nhif_remittance_id',
        'tenant_id',
        'facility_id',
        'claim_reference',
        'member_number',
        'patient_name',
        'claimed_amount',
        'approved_amount',
        'rejected_amount',
        'settled_amount',
        'decision',
        'decision_reason',
        'raw_data',
        'reconciliation_status',
        'matched_claim_submission_id',
        'matched_claims_insurance_case_id',
    ];

    protected function casts(): array
    {
        return [
            'claimed_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'rejected_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'raw_data' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
