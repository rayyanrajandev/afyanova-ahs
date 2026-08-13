<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BillingPayerContractPriceOverrideModel extends Model
{
    use HasUuids;

    protected $table = 'billing_payer_contract_price_overrides';

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
        'chargeable_item_id',
        'service_code',
        'service_name',
        'service_type',
        'department',
        'currency_code',
        'pricing_strategy',
        'override_value',
        'effective_from',
        'effective_to',
        'override_notes',
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
            'override_value' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
