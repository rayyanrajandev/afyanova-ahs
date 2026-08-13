<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformSubscriptionPlanEntitlementModel extends Model
{
    use HasUuids;

    protected $table = 'platform_subscription_plan_entitlements';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'plan_id',
        'entitlement_key',
        'entitlement_label',
        'entitlement_group',
        'entitlement_type',
        'limit_value',
        'enabled',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limit_value' => 'integer',
            'enabled' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PlatformSubscriptionPlanModel, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscriptionPlanModel::class, 'plan_id');
    }
}
