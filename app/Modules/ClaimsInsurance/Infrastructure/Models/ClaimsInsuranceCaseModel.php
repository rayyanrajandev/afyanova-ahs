<?php

namespace App\Modules\ClaimsInsurance\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClaimsInsuranceCaseModel extends Model
{
    use HasUuids;

    protected $table = 'claims_insurance_cases';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'claim_number',
        'tenant_id',
        'facility_id',
        'invoice_id',
        'patient_id',
        'patient_insurance_record_id',
        'admission_id',
        'appointment_id',
        'payer_type',
        'payer_name',
        'payer_plan_name',
        'payer_reference',
        'member_id',
        'policy_number',
        'card_number',
        'verification_reference',
        'claim_readiness',
        'claim_amount',
        'currency_code',
        'submitted_at',
        'adjudicated_at',
        'approved_amount',
        'rejected_amount',
        'settled_amount',
        'reconciliation_shortfall_amount',
        'settled_at',
        'settlement_reference',
        'decision_reason',
        'notes',
        'status',
        'reconciliation_status',
        'reconciliation_exception_status',
        'reconciliation_follow_up_status',
        'reconciliation_follow_up_due_at',
        'reconciliation_follow_up_note',
        'reconciliation_follow_up_updated_at',
        'reconciliation_follow_up_updated_by_user_id',
        'reconciliation_notes',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'adjudicated_at' => 'datetime',
            'claim_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'rejected_amount' => 'decimal:2',
            'settled_amount' => 'decimal:2',
            'reconciliation_shortfall_amount' => 'decimal:2',
            'settled_at' => 'datetime',
            'claim_readiness' => 'array',
            'reconciliation_follow_up_due_at' => 'datetime',
            'reconciliation_follow_up_updated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
