<?php

namespace App\Modules\Platform\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacilitySubscriptionModel extends Model
{
    use HasUuids;

    protected $table = 'facility_subscriptions';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'plan_id',
        'status',
        'billing_cycle',
        'price_amount',
        'currency_code',
        'trial_ends_at',
        'current_period_starts_at',
        'current_period_ends_at',
        'next_invoice_at',
        'grace_period_ends_at',
        'suspended_at',
        'cancellation_effective_at',
        'status_reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'next_invoice_at' => 'datetime',
            'grace_period_ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancellation_effective_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<TenantModel, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(TenantModel::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<FacilityModel, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(FacilityModel::class, 'facility_id');
    }

    /**
     * @return BelongsTo<PlatformSubscriptionPlanModel, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformSubscriptionPlanModel::class, 'plan_id');
    }
}
