<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatformSubscriptionPlanModel extends Model
{
    use HasUuids;

    protected $table = 'platform_subscription_plans';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'billing_cycle',
        'price_amount',
        'currency_code',
        'status',
        'sort_order',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<PlatformSubscriptionPlanEntitlementModel, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlatformSubscriptionPlanEntitlementModel::class, 'plan_id')
            ->orderBy('entitlement_group')
            ->orderBy('entitlement_label');
    }
}
