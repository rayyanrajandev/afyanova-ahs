<?php

namespace App\Modules\Billing\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PricingEngineShadowDiffModel extends Model
{
    use HasUuids;

    protected $table = 'pricing_engine_shadow_diffs';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'source_kind',
        'source_id',
        'chargeable_item_id',
        'legacy_service_code',
        'legacy_unit_price',
        'legacy_currency_code',
        'legacy_pricing_status',
        'new_unit_price',
        'new_currency_code',
        'new_pricing_status',
        'matched',
        'mismatch_reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legacy_unit_price' => 'decimal:2',
            'new_unit_price' => 'decimal:2',
            'matched' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
