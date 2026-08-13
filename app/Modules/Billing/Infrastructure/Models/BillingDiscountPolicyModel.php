<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingDiscountPolicyModel extends Model
{
    use HasUuids;

    protected $table = 'billing_discount_policies';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'description',
        'discount_type',
        'discount_value',
        'discount_percentage',
        'applicable_services',
        'auto_apply',
        'requires_approval_above_amount',
        'active_from_date',
        'active_to_date',
        'status',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'discount_percentage' => 'decimal:2',
            'requires_approval_above_amount' => 'decimal:2',
            'applicable_services' => 'array',
            'auto_apply' => 'boolean',
            'active_from_date' => 'datetime',
            'active_to_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get discounts applied using this policy
     */
    public function appliedDiscounts(): HasMany
    {
        return $this->hasMany(BillingDiscountModel::class, 'billing_discount_policy_id', 'id');
    }
}
