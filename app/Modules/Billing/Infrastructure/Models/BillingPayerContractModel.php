<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPayerContractModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payer_contracts';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'contract_code',
        'contract_name',
        'payer_type',
        'payer_name',
        'payer_plan_code',
        'payer_plan_name',
        'currency_code',
        'default_coverage_percent',
        'default_copay_type',
        'default_copay_value',
        'requires_pre_authorization',
        'claim_submission_deadline_days',
        'settlement_cycle_days',
        'effective_from',
        'effective_to',
        'terms_and_notes',
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
            'default_coverage_percent' => 'decimal:2',
            'default_copay_value' => 'decimal:2',
            'requires_pre_authorization' => 'boolean',
            'claim_submission_deadline_days' => 'integer',
            'settlement_cycle_days' => 'integer',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
