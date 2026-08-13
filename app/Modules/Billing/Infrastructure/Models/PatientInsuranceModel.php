<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientInsuranceModel extends Model
{
    use HasUuids;

    protected $table = 'patient_insurance_records';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'billing_payer_contract_id',
        'insurance_type',
        'insurance_provider',
        'provider_code',
        'plan_name',
        'policy_number',
        'member_id',
        'principal_member_name',
        'relationship_to_principal',
        'card_number',
        'effective_date',
        'expiry_date',
        'coverage_level',
        'copay_percent',
        'coverage_limit_amount',
        'status',
        'verification_status',
        'verification_date',
        'verification_source',
        'verification_reference',
        'last_verified_at',
        'verified_by_user_id',
        'notes',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'datetime',
            'expiry_date' => 'datetime',
            'copay_percent' => 'decimal:2',
            'coverage_limit_amount' => 'decimal:2',
            'verification_date' => 'datetime',
            'last_verified_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Check if insurance is currently active
     */
    public function isActive(): bool
    {
        $now = now();

        return $this->status === 'active'
            && $this->effective_date <= $now
            && ($this->expiry_date === null || $this->expiry_date >= $now);
    }
}
