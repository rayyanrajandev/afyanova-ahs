<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingNhifClaimSubmissionModel extends Model
{
    use HasUuids;

    protected $table = 'billing_nhif_claim_submissions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'tenant_id',
        'facility_id',
        'claims_insurance_case_id',
        'billing_invoice_id',
        'nhif_claim_reference',
        'submission_status',
        'submitted_amount',
        'claim_payload',
        'response_payload',
        'error_message',
        'submitted_at',
        'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'claim_payload' => 'array',
            'response_payload' => 'array',
            'submitted_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
