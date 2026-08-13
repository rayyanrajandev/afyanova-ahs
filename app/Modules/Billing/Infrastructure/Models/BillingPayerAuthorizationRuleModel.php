<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPayerAuthorizationRuleModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payer_authorization_rules';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'billing_payer_contract_id',
        'tenant_id',
        'facility_id',
        'billing_service_catalog_item_id',
        'rule_code',
        'rule_name',
        'service_code',
        'service_type',
        'department',
        'diagnosis_code',
        'priority',
        'min_patient_age_years',
        'max_patient_age_years',
        'gender',
        'amount_threshold',
        'quantity_limit',
        'coverage_decision',
        'coverage_percent_override',
        'copay_type',
        'copay_value',
        'benefit_limit_amount',
        'effective_from',
        'effective_to',
        'requires_authorization',
        'auto_approve',
        'authorization_validity_days',
        'rule_notes',
        'rule_expression',
        'metadata',
        'status',
        'status_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_patient_age_years' => 'integer',
            'max_patient_age_years' => 'integer',
            'amount_threshold' => 'decimal:2',
            'quantity_limit' => 'integer',
            'coverage_percent_override' => 'decimal:2',
            'copay_value' => 'decimal:2',
            'benefit_limit_amount' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'requires_authorization' => 'boolean',
            'auto_approve' => 'boolean',
            'authorization_validity_days' => 'integer',
            'rule_expression' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
